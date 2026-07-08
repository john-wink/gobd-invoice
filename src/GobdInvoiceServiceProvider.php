<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use JohnWink\GobdInvoice\Audit\AppendOnlyAuditLogger;
use JohnWink\GobdInvoice\Audit\ContentHasher;
use JohnWink\GobdInvoice\Contracts\AuditLogger;
use JohnWink\GobdInvoice\Contracts\DocumentContentValidator;
use JohnWink\GobdInvoice\Contracts\DocumentTotalsCalculator;
use JohnWink\GobdInvoice\Contracts\EInvoiceSerializer;
use JohnWink\GobdInvoice\Contracts\InvoiceDocument;
use JohnWink\GobdInvoice\Contracts\KleinunternehmerRule;
use JohnWink\GobdInvoice\Contracts\NumberSequenceGenerator;
use JohnWink\GobdInvoice\Contracts\TaxRateResolver;
use JohnWink\GobdInvoice\Contracts\TotalsCalculator;
use JohnWink\GobdInvoice\EInvoice\ZugferdCiiSerializer;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Numbering\FastSequenceGenerator;
use JohnWink\GobdInvoice\Numbering\LockingSequenceGenerator;
use JohnWink\GobdInvoice\Tax\GroupedDocumentTotalsCalculator;
use JohnWink\GobdInvoice\Tax\GroupedTotalsCalculator;
use JohnWink\GobdInvoice\Tax\PeriodTaxRateResolver;
use JohnWink\GobdInvoice\Tax\ThresholdKleinunternehmerRule;
use JohnWink\GobdInvoice\Validation\MandatoryContentValidator;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\TaxRatePeriod;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class GobdInvoiceServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('gobd-invoice')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasMigrations([
                'create_gobd_documents_table',
                'create_gobd_document_lines_table',
                'create_gobd_number_sequences_table',
                'create_gobd_audit_log_table',
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(
            ContentHasher::class,
            static fn (): ContentHasher => new ContentHasher(
                Config::string('gobd-invoice.audit.hash_algorithm', 'sha256'),
            ),
        );

        $this->app->bind(
            // 'gapless' (default) trades throughput for a strictly gapless sequence;
            // 'fast' trades gaplessness for high concurrency. See the generators.
            static fn (): NumberSequenceGenerator => Config::string('gobd-invoice.numbering.strategy', 'gapless') === 'fast'
            ? new FastSequenceGenerator
            : new LockingSequenceGenerator);
        $this->app->bind(TotalsCalculator::class, GroupedTotalsCalculator::class);
        $this->app->bind(DocumentTotalsCalculator::class, GroupedDocumentTotalsCalculator::class);
        $this->app->bind(TaxRateResolver::class, static fn (): TaxRateResolver => new PeriodTaxRateResolver(
            self::configuredRatePeriods(),
            Config::string('gobd-invoice.tax.standard_rate', '19.0'),
            Config::string('gobd-invoice.tax.reduced_rate', '7.0'),
        ));
        // §19 turnover limits are EUR by law (German Kleinunternehmer regime);
        // only the numeric values are configurable.
        $this->app->bind(KleinunternehmerRule::class, static fn (): KleinunternehmerRule => new ThresholdKleinunternehmerRule(
            Money::fromDecimal(Config::string('gobd-invoice.tax.kleinunternehmer_limits.prior_year', '25000.00')),
            Money::fromDecimal(Config::string('gobd-invoice.tax.kleinunternehmer_limits.current_year', '100000.00')),
        ));
        $this->app->bind(AuditLogger::class, AppendOnlyAuditLogger::class);
        $this->app->bind(DocumentContentValidator::class, MandatoryContentValidator::class);

        // E-invoice serialization. ZUGFeRD/Factur-X and XRechnung-CII are all
        // produced by the CII serializer; the configured format selects the
        // profile (XRechnung is the German CIUS, otherwise the ZUGFeRD profile).
        $this->app->bind(static function (): EInvoiceSerializer {
            $format = Config::string('gobd-invoice.einvoice.default_format', 'zugferd');
            $profile = $format === 'xrechnung'
                ? 'xrechnung'
                : Config::string('gobd-invoice.einvoice.zugferd_profile', 'en16931');

            return new ZugferdCiiSerializer($profile);
        });

        // Let host apps swap the document model (the spatie/laravel-permission pattern).
        $this->app->bind(static function (Application $application): InvoiceDocument {
            /** @var InvoiceDocument $document */
            $document = $application->make(Config::string('gobd-invoice.models.document', Document::class));

            return $document;
        });

        $this->app->singleton(GobdInvoiceManager::class);
    }

    /**
     * Build the configured effective-date rate periods. A malformed entry throws
     * rather than being silently skipped — a mis-configured legal rate must fail
     * loud, never fall back to a wrong VAT rate.
     *
     * @return array<int, TaxRatePeriod>
     */
    private static function configuredRatePeriods(): array
    {
        $periods = [];

        foreach (Config::array('gobd-invoice.tax.rate_periods', []) as $index => $entry) {
            throw_unless(is_array($entry), InvalidArgumentException::class, "gobd-invoice.tax.rate_periods[{$index}] must be an array.");

            $from = $entry['from'] ?? null;
            $standard = $entry['standard'] ?? null;
            $reduced = $entry['reduced'] ?? null;

            // Reject booleans explicitly: (string) true === '1' would otherwise
            // pass as a silent 1 % rate (and false as '' — an asymmetric trap).
            throw_if(! is_string($from) || ! is_scalar($standard) || ! is_scalar($reduced) || is_bool($standard) || is_bool($reduced), InvalidArgumentException::class, "gobd-invoice.tax.rate_periods[{$index}] needs a string 'from' date and scalar, non-boolean 'standard'/'reduced' rates.");

            $periods[] = new TaxRatePeriod($from, (string) $standard, (string) $reduced);
        }

        return $periods;
    }
}
