<?php

namespace App\Http\Controllers\Api\V1\AmkWebhook;

use App\Enums\SlotWebhookResponseCode;
use App\Enums\TransactionName;
use App\Http\Controllers\Api\V1\AmkWebhook\Traits\ApiWebhook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Slot\SlotWebhookRequest;
use App\Models\Admin\GameType;
use App\Models\Admin\GameTypeProduct;
use App\Models\Admin\Product;
use App\Models\User;
use App\Services\Slot\SlotWebhookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PlaceBetController extends Controller
{
    use ApiWebhook;

    public function placeBetNew(SlotWebhookRequest $request)
    {
        $userId = $request->getMember()->id;

        // Acquire Redis lock with retry logic
        if (! $this->acquireRedisLock($userId)) {
            return response()->json([
                'message' => 'Another transaction is currently processing. Please try again later.',
                'userId' => $userId,
            ], 409); // 409 Conflict
        }

        // Validate the request
        $validator = $request->check();
        if ($validator->fails()) {
            Redis::del("wallet:lock:$userId");

            return $validator->getResponse();
        }

        // Retrieve transactions from the request
        $transactions = $validator->getRequestTransactions();
        if (! is_array($transactions) || empty($transactions)) {
            Redis::del("wallet:lock:$userId");

            return response()->json([
                'message' => 'Invalid transaction data format.',
                'details' => $transactions,
            ], 400); // 400 Bad Request
        }

        $beforeBalance = $request->getMember()->balanceFloat;
        $event = $this->createEvent($request);
        DB::beginTransaction();
        try {
            $this->insertBets($transactions, $event);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Redis::del("wallet:lock:$userId");
            Log::error('Error during placeBet', ['error' => $e]);

            return response()->json(['message' => $e->getMessage()], 500);
        }

        // Process wallet updates
        try {
            foreach ($transactions as $transaction) {
                $this->processTransaction($request, $transaction);
            }
            $request->getMember()->wallet->refreshBalance();
            $afterBalance = $request->getMember()->balanceFloat;
        } catch (\Exception $e) {
            Log::error('Error during wallet transfer processing', ['error' => $e]);
            Redis::del("wallet:lock:$userId");

            return response()->json(['message' => $e->getMessage()], 500);
        }

        // Release Redis lock
        Redis::del("wallet:lock:$userId");

        return SlotWebhookService::buildResponse(
            SlotWebhookResponseCode::Success,
            $afterBalance,
            $beforeBalance
        );
    }

    private function acquireRedisLock($userId)
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            if (Redis::set("wallet:lock:$userId", true, 'EX', 15, 'NX')) {
                return true;
            }
            $attempts++;
            sleep(1); // Wait for 1 second before retrying
        }

        return false;
    }

    private function processTransaction($request, $transaction)
    {
        $fromUser = $request->getMember();
        $toUser = User::adminUser();

        $gameType = GameType::where('code', $transaction->GameType)->firstOrFail();
        $product = Product::where('code', $transaction->ProductID)->firstOrFail();
        $rate = GameTypeProduct::where('game_type_id', $gameType->id)
            ->where('product_id', $product->id)
            ->firstOrFail()
            ->rate;

        $meta = [
            'wager_id' => $transaction->WagerID,
            'event_id' => $request->getMessageID(),
            'seamless_transaction_id' => $transaction->TransactionID,
        ];

        $this->processTransfer(
            $fromUser,
            $toUser,
            TransactionName::Stake,
            $transaction->TransactionAmount,
            $rate,
            $meta
        );
    }
}
