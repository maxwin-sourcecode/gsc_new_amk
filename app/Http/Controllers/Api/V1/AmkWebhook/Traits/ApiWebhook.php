<?php

namespace App\Http\Controllers\Api\V1\AmkWebhook\Traits;

use App\Enums\TransactionName;
use App\Enums\WagerStatus;
use App\Http\Requests\Slot\SlotWebhookRequest;
use App\Models\Admin\GameType;
use App\Models\Admin\GameTypeProduct;
use App\Models\Admin\Product;
use App\Models\SeamlessEvent;
use App\Models\User;
use App\Models\Wager;
use App\Services\WalletService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

trait ApiWebhook
{
    public function insertBets(array $bets, SeamlessEvent $event)
    {
        $chunkSize = 50; // Define the chunk size
        $batches = array_chunk($bets, $chunkSize);

        DB::transaction(function () use ($batches, $event) {
            foreach ($batches as $batch) {
                $this->createWagerTransactions($batch, $event);
            }
        });

        return count($bets) . ' bets inserted successfully.';
    }

    public function createWagerTransactions(array $betBatch, SeamlessEvent $event)
    {
        $retryCount = 0;
        $maxRetries = 5;

        do {
            try {
                DB::transaction(function () use ($betBatch, $event) {
                    $wagerData = [];
                    $seamlessTransactionsData = [];

                    foreach ($betBatch as $transaction) {
                        $gameType = GameType::where('code', $transaction->GameType)->firstOrFail();
                        $product = Product::where('code', $transaction->ProductID)->firstOrFail();
                        $rate = GameTypeProduct::where('game_type_id', $gameType->id)
                            ->where('product_id', $product->id)
                            ->firstOrFail()
                            ->rate;

                        $wagerData[] = [
                            'user_id' => $event->user_id,
                            'seamless_wager_id' => $transaction->WagerID,
                            'status' => $transaction->TransactionAmount > 0 ? WagerStatus::Win : WagerStatus::Lose,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        $seamlessTransactionsData[] = [
                            'user_id' => $event->user_id,
                            'wager_id' => null, // Will be updated after wager insertion
                            'game_type_id' => $gameType->id,
                            'product_id' => $product->id,
                            'seamless_transaction_id' => $transaction->TransactionID,
                            'rate' => $rate,
                            'transaction_amount' => $transaction->TransactionAmount,
                            'bet_amount' => $transaction->BetAmount,
                            'valid_amount' => $transaction->ValidBetAmount,
                            'status' => $transaction->Status,
                            'seamless_event_id' => $event->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    if (!empty($wagerData)) {
                        DB::table('wagers')->insert($wagerData);
                    }

                    if (!empty($seamlessTransactionsData)) {
                        DB::table('seamless_transactions')->insert($seamlessTransactionsData);
                    }
                });

                break; // Exit the retry loop if successful
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '40001') { // Deadlock error code
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        throw $e; // Max retries reached, fail
                    }
                    sleep(1); // Wait for a second before retrying
                } else {
                    throw $e; // Rethrow non-deadlock exceptions
                }
            }
        } while ($retryCount < $maxRetries);
    }

    public function processTransfer(User $from, User $to, TransactionName $transactionName, float $amount, int $rate, array $meta)
    {
        $retryCount = 0;
        $maxRetries = 5;

        do {
            try {
                DB::transaction(function () use ($from, $to, $amount, $transactionName, $meta) {
                    $walletFrom = $from->wallet()->lockForUpdate()->firstOrFail();
                    $walletTo = $to->wallet()->lockForUpdate()->firstOrFail();

                    $walletFrom->balance -= $amount;
                    $walletTo->balance += $amount;

                    $walletFrom->save();
                    $walletTo->save();

                    app(WalletService::class)->transfer($from, $to, abs($amount), $transactionName, $meta);
                });

                break; // Exit loop if successful
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '40001') { // Deadlock error code
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        throw $e; // Max retries reached, fail
                    }
                    sleep(1); // Wait before retrying
                } else {
                    throw $e; // Rethrow non-deadlock exceptions
                }
            }
        } while ($retryCount < $maxRetries);
    }

    public function createEvent(SlotWebhookRequest $request): SeamlessEvent
    {
        return SeamlessEvent::create([
            'user_id' => $request->getMember()->id,
            'message_id' => $request->getMessageID(),
            'product_id' => $request->getProductID(),
            'request_time' => $request->getRequestTime(),
            'raw_data' => $request->all(),
        ]);
    }
}
