<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $productsSet = [
            'Smartphones' => [
                'brands' => ['Apple' => ['iPhone 15 Pro', 'iPhone 15', 'iPhone 14', 'iPhone SE'], 'Samsung' => ['Galaxy S24 Ultra', 'Galaxy S23', 'Galaxy Z Fold 5', 'Galaxy A54'], 'Google' => ['Pixel 8 Pro', 'Pixel 8', 'Pixel 7a']],
                'variants' => ['128GB Black', '256GB Silver', '512GB Titanium', '1TB Exclusive']
            ],
            'Laptops' => [
                'brands' => ['Apple' => ['MacBook Pro 14"', 'MacBook Pro 16"', 'MacBook Air M2', 'MacBook Air M3'], 'Dell' => ['XPS 13', 'XPS 15', 'Alienware m18'], 'Lenovo' => ['ThinkPad X1 Carbon', 'Legion Pro 7i', 'Yoga 9i']],
                'variants' => ['16GB RAM / 512GB SSD', '32GB RAM / 1TB SSD', '64GB RAM / 2TB SSD']
            ],
            'Tablets' => [
                'brands' => ['Apple' => ['iPad Pro 11"', 'iPad Pro 12.9"', 'iPad Air 5th Gen'], 'Samsung' => ['Galaxy Tab S9 Ultra', 'Galaxy Tab S9'], 'Microsoft' => ['Surface Pro 9', 'Surface Go 3']],
                'variants' => ['128GB Wi-Fi', '256GB 5G', '512GB Wi-Fi']
            ],
            'Gaming' => [
                'brands' => ['Sony' => ['PlayStation 5 Slim', 'PlayStation VR2'], 'Microsoft' => ['Xbox Series X', 'Xbox Series S'], 'Nintendo' => ['Switch OLED', 'Switch Lite']],
                'variants' => ['Standard Edition', 'Digital Edition', 'Bundle Edition']
            ],
            'Audio' => [
                'brands' => ['Sony' => ['WH-1000XM5', 'WF-1000XM5'], 'Bose' => ['QuietComfort Ultra', 'SoundLink Revolve+'], 'Sennheiser' => ['Momentum 4', 'HD 800 S']],
                'variants' => ['Matte Black', 'Silver', 'Midnight Blue']
            ],
        ];

        // Ensure exactly 100 uniquely generated items
        $generatedProducts = [];

        foreach ($productsSet as $category => $data) {
            foreach ($data['brands'] as $brand => $models) {
                foreach ($models as $model) {
                    // Create base model
                    $generatedProducts[] = [
                        'name' => "$brand $model",
                        'description' => "The latest $category innovation from $brand. Top-tier specs with premium build.",
                        'variants' => $data['variants']
                    ];
                    
                    // Create a "Pro/Max" variation of the model to pad up to 100 logically
                    $generatedProducts[] = [
                        'name' => "$brand $model (2024 Edition)",
                        'description' => "Refreshed 2024 SKU of the prominent $category.",
                        'variants' => $data['variants']
                    ];
                }
            }
        }

        // We have generated plenty. Slice to exactly 100 items.
        $finalList = array_slice($generatedProducts, 0, 100);

        foreach ($finalList as $item) {
            $product = Product::create([
                'name' => $item['name'],
                'description' => $item['description'],
            ]);

            // Randomize variant availability realistically
            $subsetVariants = (array) array_rand(array_flip($item['variants']), rand(2, count($item['variants'])));
            
            foreach ($subsetVariants as $vName) {
                $product->variants()->create([
                    'name' => $vName,
                    'price' => rand(199, 1499) + 0.99,
                    'stock_total' => rand(0, 15),
                    'stock_reserved' => 0,
                ]);
            }
        }
    }
}
