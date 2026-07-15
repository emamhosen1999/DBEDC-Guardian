<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Petty Cash Ledger Report</title>
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #333;
            line-height: 1.4;
        }

        .header {
            margin-bottom: 20px;
            border-bottom: 2px solid #1a365d;
            padding-bottom: 10px;
        }

        .company-name {
            font-size: 16px;
            font-weight: bold;
            color: #1a365d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #4a5568;
            margin-top: 5px;
        }

        .meta-table {
            width: 100%;
            margin-top: 10px;
            margin-bottom: 20px;
        }

        .meta-table td {
            border: none;
            padding: 2px 0;
            font-size: 9px;
        }

        .kpi-container {
            width: 100%;
            margin-bottom: 25px;
        }

        .kpi-box {
            width: 18%;
            float: left;
            margin-right: 2%;
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 8px;
            text-align: center;
        }

        .kpi-box.last {
            margin-right: 0;
            background: #ebf8ff;
            border-color: #bee3f8;
        }

        .kpi-title {
            font-size: 8px;
            font-weight: bold;
            color: #718096;
            margin-bottom: 5px;
            text-transform: uppercase;
        }

        .kpi-value {
            font-size: 12px;
            font-weight: bold;
            color: #2d3748;
        }

        .kpi-value.positive {
            color: #38a169;
        }

        .kpi-value.negative {
            color: #e53e3e;
        }

        .clear {
            clear: both;
        }

        table.ledger-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        table.ledger-table th {
            background-color: #1a365d;
            color: #ffffff;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 9px;
            padding: 6px 8px;
            border: 1px solid #1a365d;
        }

        table.ledger-table td {
            padding: 6px 8px;
            border: 1px solid #e2e8f0;
            font-size: 9px;
            vertical-align: middle;
        }

        table.ledger-table tr:nth-child(even) {
            background-color: #fcfcfc;
        }

        .type-badge {
            display: inline-block;
            padding: 2px 5px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 7px;
            text-transform: uppercase;
        }

        .badge-expense { background-color: #fed7d7; color: #9b2c2c; }
        .badge-reimbursement { background-color: #c6f6d5; color: #22543d; }
        .badge-repayment { background-color: #bee3f8; color: #2a4365; }
        .badge-loan_taken { background-color: #edf2f7; color: #4a5568; }

        .amount-col {
            text-align: right;
            font-weight: bold;
        }

        .balance-col {
            text-align: right;
            font-weight: bold;
            background-color: #f7fafc;
        }

        .footer {
            margin-top: 40px;
            border-top: 1px solid #e2e8f0;
            padding-top: 10px;
            text-align: center;
            font-size: 8px;
            color: #a0aec0;
        }

        .signatures {
            margin-top: 60px;
            width: 100%;
        }

        .signatures td {
            border: none;
            width: 33%;
            text-align: center;
            font-size: 9px;
        }

        .signature-line {
            border-top: 1px solid #718096;
            width: 150px;
            margin: 0 auto 5px;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="company-name">Dhaka Bypass Expressway Development Co. Ltd.</div>
        <div class="report-title">Petty Cash Fund Account Statement</div>
    </div>

    <table class="meta-table">
        <tr>
            <td style="width: 15%; font-weight: bold; color: #4a5568;">Fund / Account:</td>
            <td style="width: 35%;">{{ $summary['fund_name'] }} (Fund #{{ $summary['id'] }})</td>
            <td style="width: 15%; font-weight: bold; color: #4a5568;">Statement Date:</td>
            <td style="width: 35%;">{{ $generated_at->format('d-M-Y H:i A') }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold; color: #4a5568;">Employee:</td>
            <td>{{ $loan->user->name }} ({{ $loan->user->email }})</td>
            <td style="font-weight: bold; color: #4a5568;">Initiated Date:</td>
            <td>{{ $loan->loan_date ? \Carbon\Carbon::parse($loan->loan_date)->format('d-M-Y') : 'N/A' }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold; color: #4a5568;">Approver:</td>
            <td>{{ $summary['approver_name'] ?? 'N/A' }}</td>
            <td style="font-weight: bold; color: #4a5568;">Status:</td>
            <td><span style="font-weight: bold; color: {{ $loan->status === 'active' ? '#38a169' : '#718096' }}">{{ strtoupper($loan->status) }}</span></td>
        </tr>
        @if($loan->approval_comment)
        <tr>
            <td style="font-weight: bold; color: #4a5568;">Remarks:</td>
            <td colspan="3" style="font-style: italic;">"{{ $loan->approval_comment }}"</td>
        </tr>
        @endif
    </table>

    <div class="kpi-container">
        <div class="kpi-box">
            <div class="kpi-title">Initial Loan</div>
            <div class="kpi-value">৳{{ number_format($summary['original_amount'], 2) }}</div>
        </div>
        <div class="kpi-box">
            <div class="kpi-title">Total Expenses</div>
            <div class="kpi-value negative">৳{{ number_format($summary['total_expenses'], 2) }}</div>
        </div>
        <div class="kpi-box">
            <div class="kpi-title">Reimbursements</div>
            <div class="kpi-value positive">৳{{ number_format($summary['total_reimbursements'], 2) }}</div>
        </div>
        <div class="kpi-box">
            <div class="kpi-title">Repayments</div>
            <div class="kpi-value">৳{{ number_format($summary['total_repayments'], 2) }}</div>
        </div>
        <div class="kpi-box last">
            <div class="kpi-title">Current Balance</div>
            <div class="kpi-value" style="color: #1a365d;">৳{{ number_format($summary['current_balance'], 2) }}</div>
        </div>
        <div class="clear"></div>
    </div>

    <table class="ledger-table">
        <thead>
            <tr>
                <th style="width: 12%;">Date</th>
                <th style="width: 15%;">Type</th>
                <th style="width: 18%;">Category</th>
                <th style="width: 32%;">Description</th>
                <th style="width: 11%; text-align: right;">Amount</th>
                <th style="width: 12%; text-align: right;">Balance</th>
            </tr>
        </thead>
        <tbody>
            @php $runningBalance = 0; @endphp
            @foreach($transactions as $transaction)
                @php
                    $amt = (float) $transaction['amount'];
                    if ($transaction['type'] === 'loan_taken') {
                        $runningBalance = $amt;
                    } elseif ($transaction['type'] === 'expense' || $transaction['type'] === 'repayment') {
                        $runningBalance -= $amt;
                    } elseif ($transaction['type'] === 'reimbursement') {
                        $runningBalance += $amt;
                    }
                @endphp
                <tr>
                    <td>{{ \Carbon\Carbon::parse($transaction['transaction_date'])->format('d-M-Y') }}</td>
                    <td>
                        <span class="type-badge badge-{{ $transaction['type'] }}">
                            {{ str_replace('_', ' ', $transaction['type']) }}
                        </span>
                    </td>
                    <td>
                        @if($transaction['category'])
                            {{ $categories[$transaction['category']] ?? ucwords(str_replace('_', ' ', $transaction['category'])) }}
                        @else
                            <span style="color: #a0aec0;">N/A</span>
                        @endif
                    </td>
                    <td>{{ $transaction['description'] }}</td>
                    <td class="amount-col" style="color: {{ $transaction['type'] === 'expense' || $transaction['type'] === 'repayment' ? '#e53e3e' : '#38a169' }};">
                        {{ $transaction['type'] === 'expense' || $transaction['type'] === 'repayment' ? '-' : '+' }}৳{{ number_format($amt, 2) }}
                    </td>
                    <td class="balance-col">
                        ৳{{ number_format($runningBalance, 2) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td>
                <div class="signature-line"></div>
                Prepared By (User)
            </td>
            <td>
                <div class="signature-line"></div>
                Checked By
            </td>
            <td>
                <div class="signature-line"></div>
                Approved By (Manager)
            </td>
        </tr>
    </table>

    <div class="footer">
        Dhaka Bypass Expressway Project • Confidential Report • System Generated
    </div>
</body>

</html>
