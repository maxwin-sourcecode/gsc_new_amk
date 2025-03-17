<?php

namespace App\Http\Controllers\Api\V1\Webhook\Traits;

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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

trait NewVersionOptimizedBettingProcess
{
    public function placeBet(SlotWebhookRequest $request)
    {
        $userId = $request->getMember()->id;

        // Try to acquire a Redis lock for the user's wallet
        $lock = Redis::set("wallet:lock:$userId", true, 'EX', 10, 'NX');  // 10-second lock

        if (! $lock) {
            return response()->json(['message' => 'The wallet is currently being updated. Please try again later.'], 409);
        }

        // Create and store the event in the database
        $event = $this->createEvent($request);

        DB::beginTransaction();
        try {
            // Validate the request
            $validator = $request->check();
            if ($validator->fails()) {
                Redis::del("wallet:lock:$userId");

                return $validator->getResponse();
            }

            $before_balance = $request->getMember()->balanceFloat;

            // Retry logic for creating wager transactions with exponential backoff
            $seamless_transactions = $this->retryOnDeadlock(function () use ($validator, $event) {
                return $this->createWagerTransactions($validator->getRequestTransactions(), $event);
            });

            // Process each seamless transaction
            foreach ($seamless_transactions as $seamless_transaction) {
                $this->processTransfer(
                    $request->getMember(),
                    User::adminUser(),
                    TransactionName::Stake,
                    $seamless_transaction->transaction_amount,
                    $seamless_transaction->rate,
                    [
                        'wager_id' => $seamless_transaction->wager_id,
                        'event_id' => $request->getMessageID(),
                        'seamless_transaction_id' => $seamless_transaction->id,
                    ]
                );
            }

            // Refresh balance after transactions
            $request->getMember()->wallet->refreshBalance();
            $after_balance = $request->getMember()->balanceFloat;

            DB::commit();
            Redis::del("wallet:lock::$userId");

            return response()->json([
                'balance_before' => $before_balance,
                'balance_after' => $after_balance,
                'message' => 'Bet placed successfully.',
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Redis::del("wallet:lock::$userId");

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Creates wagers in chunks and inserts them along with related seamless transactions.
     */
    public function insertBets(array $bets, SeamlessEvent $event)
    {
        $chunkSize = 50; // Define the chunk size
        $batches = array_chunk($bets, $chunkSize);

        $userId = $event->user_id; // Get user_id from SeamlessEvent

        // Process chunks in a transaction to ensure data integrity
        DB::transaction(function () use ($batches, $event) {
            foreach ($batches as $batch) {
                // Call createWagerTransactions for each batch
                $this->createWagerTransactions($batch, $event);
            }
        });

        return count($bets).' bets inserted successfully.';
    }

    /**
     * Creates wagers in chunks and inserts them along with related seamless transactions.
     */
    public function createWagerTransactions(array $betBatch, SeamlessEvent $event)
    {
        $retryCount = 0;
        $maxRetries = 5;
        $userId = $event->user_id; // Get user_id from the SeamlessEvent
        $seamlessEventId = $event->id; // Get the ID of the SeamlessEvent

        // Retry logic for deadlock handling
        do {
            try {
                // DB::transaction(function () use ($betBatch, $userId, $seamlessEventId) {
                //     // Initialize arrays for batch inserts
                //     $wagerData = [];
                //     $seamlessTransactionsData = [];

                //     // Loop through each bet in the batch
                //     foreach ($betBatch as $transaction) {
                //         // If transaction is an instance of the RequestTransaction object, extract the data
                //         if ($transaction instanceof \App\Services\Slot\Dto\RequestTransaction) {
                //             $transactionData = [
                //                 'Status' => $transaction->Status,
                //                 'ProductID' => $transaction->ProductID,
                //                 'GameType' => $transaction->GameType,
                //                 'TransactionID' => $transaction->TransactionID,
                //                 'WagerID' => $transaction->WagerID,
                //                 'BetAmount' => $transaction->BetAmount,
                //                 'TransactionAmount' => $transaction->TransactionAmount,
                //                 'PayoutAmount' => $transaction->PayoutAmount,
                //                 'ValidBetAmount' => $transaction->ValidBetAmount,

                //             ];
                //         } else {
                //             throw new \Exception('Invalid transaction data format.');
                //         }

                //         // Now, use the $transactionData array as expected
                //         $existingWager = Wager::where('seamless_wager_id', $transactionData['WagerID'])->lockForUpdate()->first();

                //         // Fetch game_type and product
                //         $game_type = GameType::where('code', $transactionData['GameType'])->first();
                //         if (! $game_type) {
                //             throw new \Exception("Game type not found for {$transactionData['GameType']}");
                //         }

                //         $product = Product::where('code', $transactionData['ProductID'])->first();
                //         if (! $product) {
                //             throw new \Exception("Product not found for {$transactionData['ProductID']}");
                //         }

                //         // Fetch the rate from GameTypeProduct
                //         $game_type_product = GameTypeProduct::where('game_type_id', $game_type->id)
                //             ->where('product_id', $product->id)
                //             ->first();

                //         $rate = $game_type_product->rate;  // Fetch rate for this transaction

                //         Log::info('Fetched rate for transaction', ['rate' => $rate]);

                //         if (! $existingWager) {
                //             // Collect wager data for batch insert
                //             $wagerData[] = [
                //                 'user_id' => $userId,  // Use user_id from the SeamlessEvent
                //                 'seamless_wager_id' => $transactionData['WagerID'],
                //                 'status' => $transactionData['TransactionAmount'] > 0 ? WagerStatus::Win : WagerStatus::Lose,
                //                 'created_at' => now(),
                //                 'updated_at' => now(),
                //             ];
                //         }

                //         // Collect seamless transaction data for batch insert
                //         $seamlessTransactionsData[] = [
                //             'user_id' => $userId,  // Use user_id from the SeamlessEvent
                //             'wager_id' => $existingWager ? $existingWager->id : null,
                //             'game_type_id' => $transactionData['GameType'],
                //             'product_id' => $transactionData['ProductID'],
                //             'seamless_transaction_id' => $transactionData['TransactionID'],
                //             'rate' => $rate,  // Include rate for the transaction
                //             'transaction_amount' => $transactionData['TransactionAmount'],
                //             'bet_amount' => $transactionData['BetAmount'],
                //             'valid_amount' => $transactionData['ValidBetAmount'],
                //             'status' => $transactionData['Status'],
                //             'seamless_event_id' => $seamlessEventId,  // Include seamless_event_id
                //             'created_at' => now(),
                //             'updated_at' => now(),
                //         ];
                //     }

                //     // Perform batch inserts
                //     if (! empty($wagerData)) {
                //         DB::table('wagers')->insert($wagerData); // Insert wagers in bulk
                //     }

                //     if (! empty($seamlessTransactionsData)) {
                //         DB::table('seamless_transactions')->insert($seamlessTransactionsData); // Insert transactions in bulk
                //     }
                // });

    DB::transaction(function () use ($betBatch, $userId, $seamlessEventId) {
    // Set the transaction isolation level to READ COMMITTED to reduce deadlock likelihood
    DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');

    // Initialize arrays for batch inserts
    $wagerData = [];
    $seamlessTransactionsData = [];

    // Loop through each bet in the batch
    foreach ($betBatch as $transaction) {
        // Extract transaction data
        if ($transaction instanceof \App\Services\Slot\Dto\RequestTransaction) {
            $transactionData = [
                'Status' => $transaction->Status,
                'ProductID' => $transaction->ProductID,
                'GameType' => $transaction->GameType,
                'TransactionID' => $transaction->TransactionID,
                'WagerID' => $transaction->WagerID,
                'BetAmount' => $transaction->BetAmount,
                'TransactionAmount' => $transaction->TransactionAmount,
                'PayoutAmount' => $transaction->PayoutAmount,
                'ValidBetAmount' => $transaction->ValidBetAmount,
            ];
        } else {
            throw new \Exception('Invalid transaction data format.');
        }

        // Check for existing wager without locking (since we're using READ COMMITTED)
        $existingWager = Wager::where('seamless_wager_id', $transactionData['WagerID'])->first();

        // Fetch game_type and product
        $game_type = GameType::where('code', $transactionData['GameType'])->first();
        if (!$game_type) {
            throw new \Exception("Game type not found for {$transactionData['GameType']}");
        }

        $product = Product::where('code', $transactionData['ProductID'])->first();
        if (!$product) {
            throw new \Exception("Product not found for {$transactionData['ProductID']}");
        }

        // Fetch the rate from GameTypeProduct
        $game_type_product = GameTypeProduct::where('game_type_id', $game_type->id)
            ->where('product_id', $product->id)
            ->first();

        if (!$game_type_product) {
            throw new \Exception("GameTypeProduct not found for game_type_id {$game_type->id} and product_id {$product->id}");
        }

        $rate = $game_type_product->rate;  // Fetch rate for this transaction

        Log::info('Fetched rate for transaction', ['rate' => $rate]);

        if (!$existingWager) {
            // Collect wager data for batch insert
            $wagerData[] = [
                'user_id' => $userId,
                'seamless_wager_id' => $transactionData['WagerID'],
                'status' => $transactionData['TransactionAmount'] > 0 ? WagerStatus::Win : WagerStatus::Lose,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Collect seamless transaction data for batch insert
        $seamlessTransactionsData[] = [
            'user_id' => $userId,
            'wager_id' => $existingWager ? $existingWager->id : null, // Will be updated later if wager is inserted
            'game_type_id' => $game_type->id,
            'product_id' => $product->id,
            'seamless_transaction_id' => $transactionData['TransactionID'],
            'rate' => $rate,
            'transaction_amount' => $transactionData['TransactionAmount'],
            'bet_amount' => $transactionData['BetAmount'],
            'valid_amount' => $transactionData['ValidBetAmount'],
            'status' => $transactionData['Status'],
            'seamless_event_id' => $seamlessEventId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    // Perform batch inserts for wagers
    if (!empty($wagerData)) {
        DB::table('wagers')->insert($wagerData);
    }

    // If wagers were inserted, fetch their IDs and update seamless transactions
    foreach ($seamlessTransactionsData as &$transactionData) {
        if (!$transactionData['wager_id']) {
            $wager = Wager::where('seamless_wager_id', $transactionData['seamless_wager_id'])->first();
            if ($wager) {
                $transactionData['wager_id'] = $wager->id;
            }
        }
    }

    // Perform batch inserts for seamless transactions
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
                    throw $e; // Rethrow if it's not a deadlock exception
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
                // Only lock the necessary rows inside the transaction
                DB::transaction(function () use ($from, $to, $amount, $transactionName, $meta) {
                    // Lock only the specific rows for the wallet that needs updating
                    $walletFrom = $from->wallet()->lockForUpdate()->firstOrFail();
                    $walletTo = $to->wallet()->lockForUpdate()->firstOrFail();

                    // Update wallet balances
                    $walletFrom->balance -= $amount;
                    $walletTo->balance += $amount;

                    // Save the updated balances
                    $walletFrom->save();
                    $walletTo->save();

                    // Perform the transfer in the wallet service (possibly outside the transaction)
                    app(WalletService::class)->transfer($from, $to, abs($amount), $transactionName, $meta);
                });

                break;  // Exit loop if successful

            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '40001') {  // Deadlock error code
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        throw $e;  // Max retries reached, fail
                    }
                    sleep(1);  // Wait before retrying
                } else {
                    throw $e;  // Rethrow non-deadlock exceptions
                }
            }
        } while ($retryCount < $maxRetries);
    }

    /**
     * Retry logic for handling deadlocks with exponential backoff.
     */
    private function retryOnDeadlock(callable $callback, $maxRetries = 5)
    {
        $retryCount = 0;

        do {
            try {
                return $callback();
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '40001') {  // Deadlock error code
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        throw $e;  // Max retries reached, fail
                    }
                    sleep(pow(2, $retryCount));  // Exponential backoff
                } else {
                    throw $e;  // Rethrow non-deadlock exceptions
                }
            }
        } while ($retryCount < $maxRetries);
    }

    /**
     * Create the event in the system.
     */
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
