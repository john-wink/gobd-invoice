<?php

declare(strict_types=1);

return [
    'document_types' => [
        'rechnung' => 'Rechnung',
        'angebot' => 'Angebot',
        'kostenvoranschlag' => 'Kostenvoranschlag',
        'beleg' => 'Beleg',
        'leistungsnachweis' => 'Leistungsnachweis',
        'teilzahlung' => 'Teilzahlung',
        'abschlagsrechnung' => 'Abschlagsrechnung',
        'schlussrechnung' => 'Schlussrechnung',
        'storno' => 'Stornorechnung',
        'gutschrift' => 'Gutschrift',
        'mahnung' => 'Mahnung',
    ],
    'statuses' => [
        'draft' => 'Entwurf',
        'finalized' => 'Festgeschrieben',
        'sent' => 'Versendet',
        'partially_paid' => 'Teilweise bezahlt',
        'paid' => 'Bezahlt',
        'overdue' => 'Überfällig',
        'cancelled' => 'Storniert',
    ],
    'tax_categories' => [
        'S' => 'Regelbesteuerung',
        'Z' => 'Nullsatz',
        'E' => 'Steuerbefreit',
        'AE' => 'Steuerschuldnerschaft des Leistungsempfängers',
        'K' => 'Innergemeinschaftliche Lieferung',
        'G' => 'Ausfuhrlieferung',
        'O' => 'Nicht steuerbar',
    ],
    'notes' => [
        'kleinunternehmer' => 'Steuerbefreiung für Kleinunternehmer gemäß § 19 UStG.',
        'reverse_charge' => 'Steuerschuldnerschaft des Leistungsempfängers (§ 13b UStG).',
        'retention' => 'Aufbewahrungspflicht: Bitte bewahren Sie diese Rechnung auf.',
    ],
    'labels' => [
        'invoice_number' => 'Rechnungsnummer',
        'issue_date' => 'Rechnungsdatum',
        'service_date' => 'Leistungsdatum',
        'net_total' => 'Nettobetrag',
        'vat' => 'Umsatzsteuer',
        'gross_total' => 'Gesamtbetrag',
    ],
];
