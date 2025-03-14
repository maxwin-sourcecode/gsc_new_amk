<?php

namespace App\Http\Controllers;

use App\Models\Admin\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('reports')
            ->join('users', 'reports.member_name', '=', 'users.user_name')
            ->select(
                'users.name as member_name',
                'users.user_name as user_name',
                DB::raw('COUNT(DISTINCT reports.id) as qty'),
                DB::raw('SUM(reports.bet_amount) as total_bet_amount'),
                DB::raw('SUM(reports.valid_bet_amount) as total_valid_bet_amount'),
                DB::raw('SUM(reports.payout_amount) as total_payout_amount'),
                DB::raw('SUM(reports.commission_amount) as total_commission_amount'),
                DB::raw('SUM(reports.jack_pot_amount) as total_jack_pot_amount'),
                DB::raw('SUM(reports.jp_bet) as total_jp_bet'),
                DB::raw('(SUM(reports.payout_amount) - SUM(reports.valid_bet_amount)) as win_or_lose'),
                DB::raw('COUNT(*) as stake_count')
            );
        if (isset($request->start_date) && isset($request->end_date)) {
            $query->whereBetween('reports.created_at', [$request->start_date.' 00:00:00', $request->end_date.' 23:59:59']);
        } elseif (isset($request->member_name)) {
            $query->where('reports.member_name', $request->member_name);
        } else {
            $currentMonthStart = Carbon::now()->startOfMonth()->format('Y-m-d H:i:s');
            $currentMonthEnd = Carbon::now()->endOfMonth()->format('Y-m-d H:i:s');

            $query->whereBetween('reports.created_at', [$currentMonthStart, $currentMonthEnd]);
        }

        if (! Auth::user()->hasRole('Admin')) {
            $query->where('reports.agent_id', Auth::id());
        }
        $agentReports = $query->groupBy('reports.member_name', 'users.name', 'users.user_name')->get();

        return view('report.show', compact('agentReports'));
    }

    // amk
    public function detail(Request $request, $userName)
    {
        $reports = DB::table('reports')
            ->join('users', 'reports.member_name', '=', 'users.user_name')
            ->join('products', 'products.code', '=', 'reports.product_code')
            ->where('reports.member_name', $userName)
            ->orderBy('reports.id', 'desc')
            ->select(
                'reports.*',
                'users.user_name as name',
                'products.name as product_name',
                DB::raw('(reports.payout_amount - reports.valid_bet_amount) as win_or_lose')
            )->get();

        $products = Product::all();

        return view('report.detail', compact('products', 'userName', 'reports'));
    }
}
