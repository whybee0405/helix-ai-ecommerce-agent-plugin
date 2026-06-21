<?php
defined('ABSPATH') || exit;

class Helix_Pack {

    // Pack definitions — add new industries here only
    private static array $packs = [
        'kbeauty' => [
            'label'       => 'K-Beauty / Cosmetics',
            'description' => 'Skincare, makeup and beauty retail',
            'icon'        => '✨',
            'color'       => '#ec4899',
            'features'    => ['routine_builder', 'skin_profile', 'product_faq', 'ask_ai', 'inline_chat', 'lead_capture', 'web_search'],
            'hidden'      => ['migrator'],
            'cta_label'   => 'Add to Bag',
            'product_noun'=> 'Product',
            'sync_noun'   => 'Products synced',
            'attribute_labels' => [
                'skin_type'   => 'Skin Type',
                'concerns'    => 'Skin Concerns',
                'ingredients' => 'Key Ingredients',
                'finish'      => 'Finish',
                'spf'         => 'SPF',
            ],
        ],
        'automotive' => [
            'label'       => 'Automotive / Car Dealership',
            'description' => 'New and used vehicle sales',
            'icon'        => '🚗',
            'color'       => '#3b82f6',
            'features'    => ['migrator', 'enquire_cta', 'ask_ai', 'inline_chat', 'lead_capture', 'web_search'],
            'hidden'      => ['routine_builder', 'skin_profile'],
            'cta_label'   => 'Enquire / Test Drive',
            'product_noun'=> 'Vehicle',
            'sync_noun'   => 'Vehicles synced',
            'attribute_labels' => [
                'make'        => 'Make',
                'model'       => 'Model',
                'year'        => 'Year',
                'mileage'     => 'Mileage',
                'engine'      => 'Engine',
                'body_type'   => 'Body Type',
                'transmission'=> 'Transmission',
                'fuel_type'   => 'Fuel Type',
                'colour'      => 'Colour',
                'vin'         => 'VIN',
            ],
        ],
        'general' => [
            'label'       => 'General Retail',
            'description' => 'Any retail or ecommerce store',
            'icon'        => '🛍️',
            'color'       => '#6366f1',
            'features'    => ['ask_ai', 'inline_chat', 'lead_capture', 'web_search', 'product_faq'],
            'hidden'      => ['migrator', 'routine_builder', 'skin_profile', 'enquire_cta'],
            'cta_label'   => 'Add to Cart',
            'product_noun'=> 'Product',
            'sync_noun'   => 'Products synced',
            'attribute_labels' => [],
        ],
    ];

    public static function current(): string {
        return get_option('helix_pack_id', 'kbeauty');
    }

    public static function config(?string $pack = null): array {
        $pack = $pack ?? self::current();
        return self::$packs[$pack] ?? self::$packs['general'];
    }

    public static function all(): array {
        return self::$packs;
    }

    public static function has_feature(string $feature, ?string $pack = null): bool {
        return in_array($feature, self::config($pack)['features'], true);
    }

    public static function is_hidden(string $feature, ?string $pack = null): bool {
        return in_array($feature, self::config($pack)['hidden'], true);
    }

    public static function label(string $key, ?string $pack = null): string {
        return self::config($pack)['attribute_labels'][$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public static function cta_label(?string $pack = null): string {
        return self::config($pack)['cta_label'];
    }

    public static function product_noun(?string $pack = null): string {
        return self::config($pack)['product_noun'];
    }
}
