<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 50px 60px 80px 60px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Greycliff', sans-serif;
            font-size: 9.5pt;
            color: #1a1a1a;
            line-height: 1.5;
            padding: 40px;
        }

        /* ── Header ── */
        .header {
            padding-bottom: 10px;
            margin-bottom: 18px;
            border-bottom: 1px solid #e0e0e0;
        }

        .logo {
            height: 30px;
        }

        .header-meta {
            font-size: 8.5pt;
            color: #888888;
            text-align: right;
        }

        /* ── Title block ── */
        .title {
            font-size: 18pt;
            font-weight: bold;
            color: #000000;
            margin-bottom: 2px;
            line-height: 1.15;
        }

        .subtitle {
            font-size: 9pt;
            color: #888888;
            margin-bottom: 4px;
        }

        .intro {
            font-size: 8.5pt;
            color: #555555;
            line-height: 1.45;
            margin-bottom: 16px;
        }

        /* ── Badge (red label, white text, rounded) ── */
        .badge {
            display: inline-block;
            background-color: #F23036;
            color: #ffffff;
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 3px 8px;
            border-radius: 4px;
            line-height: 1;
        }

        .badge.neutral {
            background-color: #555555;
        }

        .badge.positive {
            background-color: #16a34a;
        }

        .badge.negative {
            background-color: #F23036;
        }

        /* ── Metric cards (individual cards) ── */
        .metrics-row {
            margin-bottom: 16px;
        }

        .metric-card {
            border: 1px solid #d5d5d5;
            border-radius: 8px;
            padding: 10px 14px;
            vertical-align: top;
            background-color: #ffffff;
        }

        .metric-card-spacer {
            width: 10px;
            border: none;
            padding: 0 !important;
            background-color: transparent;
        }

        .metric-card-label {
            margin-bottom: 6px;
        }

        .metric-value {
            font-size: 16pt;
            font-weight: bold;
            color: #000000;
            line-height: 1.15;
            margin-bottom: 4px;
        }

        .metric-change {
            font-size: 8pt;
            color: #888888;
        }

        /* ── Section headers ── */
        h2 {
            font-size: 11pt;
            font-weight: bold;
            color: #000000;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-top: 14px;
            margin-bottom: 6px;
        }

        /* ── Tables (shadcn Table style) ── */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 9pt;
        }

        thead th {
            background-color: transparent;
            color: #888888;
            font-weight: bold;
            text-align: left;
            padding: 6px 12px;
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e0e0e0;
        }

        tbody td {
            padding: 6px 12px;
            border-bottom: 1px solid #f0f0f0;
            color: #1a1a1a;
        }

        tbody tr:last-child td {
            border-bottom: 1px solid #e0e0e0;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        /* ── Highlight & bold ── */
        .highlight {
            color: #F23036;
            font-weight: bold;
        }

        .bold {
            font-weight: bold;
        }

        /* ── Analysis card (shadcn Card style) ── */
        .analysis-card {
            background-color: #fafafa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 10px 14px;
            margin: 8px 0;
            line-height: 1.55;
            font-size: 9pt;
        }

        .analysis-card p {
            margin-bottom: 6px;
        }

        .analysis-card p:last-child {
            margin-bottom: 0;
        }

        /* ── Lists ── */
        ul, ol {
            margin-left: 16px;
            margin-bottom: 10px;
        }

        li {
            margin-bottom: 3px;
            line-height: 1.5;
        }

        /* ── Footer ── */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 0 100px 24px 100px;
            font-size: 7.5pt;
            color: #cccccc;
            text-align: center;
        }

        .footer-quote {
            font-style: italic;
            font-size: 8pt;
            color: #bbbbbb;
            margin-bottom: 6px;
        }

        .footer-meta {
            font-size: 7pt;
            color: #cccccc;
        }

        /* ── Utility ── */
        .page-break {
            page-break-after: always;
        }

        .avoid-break {
            page-break-inside: avoid;
        }

        .muted {
            color: #888888;
        }

        .small {
            font-size: 8.5pt;
        }

        .tag {
            display: inline-block;
            background-color: #f0f0f0;
            padding: 2px 8px;
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 4px;
        }

        .tag.red {
            background-color: #F23036;
            color: #ffffff;
        }
    </style>
</head>
<body>

    {{-- Header --}}
    <div class="header">
        <table style="border: none; margin: 0;">
            <tr>
                <td style="border: none; padding: 0; width: 50%;">
                    <img src="{{ $logo }}" class="logo" alt="Cyclowax">
                </td>
                <td style="border: none; padding: 0; width: 50%; text-align: right;">
                    <div class="header-meta">
                        {{ $date ?? now()->format('d M Y') }}
                        @if(isset($context))
                            <br>{{ $context }}
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Title --}}
    <div class="title">{{ $title }}</div>
    @if(isset($subtitle))
        <div class="subtitle">{{ $subtitle }}</div>
    @endif

    {{-- Intro summary --}}
    @if(isset($intro))
        <div class="intro">{{ $intro }}</div>
    @endif

    {{-- Key metrics (optional) --}}
    @if(isset($metrics) && count($metrics) > 0)
        <div class="metrics-row">
            <table style="margin: 0; border: none; border-collapse: separate; border-spacing: 8px 0;">
                <tr>
                    @foreach($metrics as $metric)
                        <td class="metric-card" style="width: {{ round(100 / count($metrics)) }}%;">
                            <div class="metric-card-label">
                                <span class="badge">{{ $metric['label'] }}</span>
                            </div>
                            <div class="metric-value">{{ $metric['value'] }}</div>
                            @if(isset($metric['change']))
                                <div class="metric-change">
                                    {{ $metric['change'] }}
                                </div>
                            @endif
                        </td>
                    @endforeach
                </tr>
            </table>
        </div>
    @endif

    {{-- Dynamic content sections --}}
    @foreach($sections as $section)
        @if($section['type'] === 'heading')
            <h2>{{ $section['content'] }}</h2>

        @elseif($section['type'] === 'text')
            <p style="margin-bottom: 10px;">{!! $section['content'] !!}</p>

        @elseif($section['type'] === 'analysis')
            <div class="analysis-card avoid-break">
                {!! $section['content'] !!}
            </div>

        @elseif($section['type'] === 'table')
            <table>
                <thead>
                    <tr>
                        @foreach($section['headers'] as $header)
                            <th class="{{ $header['align'] ?? '' }}" @if(isset($header['width'])) style="width: {{ $header['width'] }}" @endif>{{ $header['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($section['rows'] as $row)
                        <tr>
                            @foreach($row as $i => $cell)
                                <td class="{{ $section['headers'][$i]['align'] ?? '' }} {{ $cell['class'] ?? '' }}">
                                    {{ $cell['value'] ?? $cell }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>

        @elseif($section['type'] === 'list')
            <div class="avoid-break">
                <ul>
                    @foreach($section['items'] as $item)
                        <li>{!! $item !!}</li>
                    @endforeach
                </ul>
            </div>

        @elseif($section['type'] === 'page-break')
            <div class="page-break"></div>
        @endif
    @endforeach

    {{-- Footer --}}
    <div class="footer">
        @if(isset($quote))
            <div class="footer-quote">{{ $quote }}</div>
        @endif
        <table class="footer-meta" style="border: none; margin: 0; width: 100%;">
            <tr>
                <td style="border: none; padding: 0; width: 33%; color: #cccccc; text-align: left;">
                    {{ $title }}
                </td>
                <td style="border: none; padding: 0; width: 34%; color: #cccccc; text-align: center;">
                    Cyclowax
                </td>
                <td style="border: none; padding: 0; width: 33%; color: #cccccc; text-align: right;">
                    {{ $date ?? now()->format('d M Y') }}
                </td>
            </tr>
        </table>
    </div>

</body>
</html>
