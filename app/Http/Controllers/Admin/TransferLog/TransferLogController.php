<?php

namespace App\Http\Controllers\Admin\TransferLog;

use App\Http\Controllers\Controller;
use App\Models\Admin\TransferLog;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class TransferLogController extends Controller
{
    public function index()
    {
        $this->authorize('transfer_log', User::class);
        $transferLogs = Auth::user()->transactions()->with('targetUser')
            ->whereIn('transactions.type', ['withdraw', 'deposit'])
            ->whereIn('transactions.name', ['credit_transfer', 'debit_transfer'])
            ->latest()->paginate();

        return view('admin.trans_log.index', compact('transferLogs'));
    }

    public function transferLog($id)
    {
        abort_if(
            Gate::denies('make_transfer') || ! $this->ifChildOfParent(request()->user()->id, $id),
            Response::HTTP_FORBIDDEN,
            '403 Forbidden | You cannot access this page because you do not have permission'
        );

        $transferLogs = Auth::user()->transactions()->with('targetUser')
            ->whereIn('transactions.type', ['withdraw', 'deposit'])
            ->whereIn('transactions.name', ['credit_transfer', 'debit_transfer'])
            ->where('target_user_id', $id)->latest()->paginate();

        return view('admin.trans_log.detail', compact('transferLogs'));
    }
}
