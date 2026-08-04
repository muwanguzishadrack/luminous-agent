<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The 21 industries accepted by the business-profile `vertical` parameter
 * (docs/reference/whatsapp-cloud-api.md §5). Meta accepts the empty string
 * as "unset", which is modelled as a null vertical rather than a case.
 *
 * These are **not** MBA's five supported verticals (D-018) — different list,
 * different purpose.
 */
#[TypeScript]
enum WhatsAppVertical: string
{
    case Alcohol = 'ALCOHOL';
    case Apparel = 'APPAREL';
    case Auto = 'AUTO';
    case Beauty = 'BEAUTY';
    case Edu = 'EDU';
    case Entertain = 'ENTERTAIN';
    case EventPlan = 'EVENT_PLAN';
    case Finance = 'FINANCE';
    case Govt = 'GOVT';
    case Grocery = 'GROCERY';
    case Health = 'HEALTH';
    case Hotel = 'HOTEL';
    case Nonprofit = 'NONPROFIT';
    case OnlineGambling = 'ONLINE_GAMBLING';
    case OtcDrugs = 'OTC_DRUGS';
    case Other = 'OTHER';
    case PhysicalGambling = 'PHYSICAL_GAMBLING';
    case ProfServices = 'PROF_SERVICES';
    case Restaurant = 'RESTAURANT';
    case Retail = 'RETAIL';
    case Travel = 'TRAVEL';

    /**
     * Meta's own human label for the industry, as WhatsApp Manager shows it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Alcohol => 'Alcoholic beverages',
            self::Apparel => 'Clothing and apparel',
            self::Auto => 'Automotive',
            self::Beauty => 'Beauty, spa and salon',
            self::Edu => 'Education',
            self::Entertain => 'Entertainment',
            self::EventPlan => 'Event planning and service',
            self::Finance => 'Finance and banking',
            self::Govt => 'Public service',
            self::Grocery => 'Food and grocery',
            self::Health => 'Medical and health',
            self::Hotel => 'Hotel and lodging',
            self::Nonprofit => 'Non-profit',
            self::OnlineGambling => 'Online gambling and gaming',
            self::OtcDrugs => 'Over-the-counter drugs',
            self::Other => 'Other',
            self::PhysicalGambling => 'Non-online gambling and gaming',
            self::ProfServices => 'Professional services',
            self::Restaurant => 'Restaurant',
            self::Retail => 'Shopping and retail',
            self::Travel => 'Travel and transportation',
        };
    }

    /**
     * Every vertical as a select option, in Meta's own order.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $vertical): array => ['value' => $vertical->value, 'label' => $vertical->label()],
            self::cases(),
        );
    }
}
