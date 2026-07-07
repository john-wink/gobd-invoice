<?php

declare(strict_types=1);

return [
    'document_types' => [
        'rechnung' => 'Invoice',
        'angebot' => 'Quote',
        'kostenvoranschlag' => 'Cost estimate',
        'beleg' => 'Receipt',
        'leistungsnachweis' => 'Proof of performance',
        'teilzahlung' => 'Partial payment',
        'abschlagsrechnung' => 'Progress invoice',
        'schlussrechnung' => 'Final invoice',
        'storno' => 'Cancellation invoice',
        'gutschrift' => 'Credit note',
        'mahnung' => 'Dunning notice',
    ],
    'statuses' => [
        'draft' => 'Draft',
        'finalized' => 'Finalized',
        'sent' => 'Sent',
        'partially_paid' => 'Partially paid',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'cancelled' => 'Cancelled',
    ],
    'tax_categories' => [
        'S' => 'Standard rate',
        'Z' => 'Zero rated',
        'E' => 'Exempt',
        'AE' => 'Reverse charge',
        'K' => 'Intra-community supply',
        'G' => 'Export',
        'O' => 'Out of scope',
    ],
    'notes' => [
        'kleinunternehmer' => 'VAT-exempt small business under § 19 UStG (Kleinunternehmer).',
        'reverse_charge' => 'Reverse charge: the recipient is liable for VAT (§ 13b UStG).',
        'retention' => 'Retention obligation: please keep this invoice on file.',
    ],
    'labels' => [
        'invoice_number' => 'Invoice number',
        'issue_date' => 'Invoice date',
        'service_date' => 'Service date',
        'net_total' => 'Net total',
        'vat' => 'VAT',
        'gross_total' => 'Total',
    ],
];
