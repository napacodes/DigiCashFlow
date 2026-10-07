<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CmsBaselineSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['pages', 'page_components', 'page_component_repeated_contents', 'languages'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->command?->warn("CMS baseline skipped: missing {$table} table.");

                return;
            }
        }

        $baselinePath = database_path('seeders/data/cms_baseline.json');
        if (! is_file($baselinePath)) {
            throw new RuntimeException('CMS baseline data file is missing.');
        }

        $baseline = json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($baseline): void {
            $hasDefaultLanguage = DB::table('languages')->where('is_default', true)->exists();
            $languagesChanged = false;

            foreach ($baseline['languages'] as $language) {
                if (DB::table('languages')->where('code', $language['code'])->exists()) {
                    continue;
                }

                $isDefault = $language['is_default'] && ! $hasDefaultLanguage;
                DB::table('languages')->insert([
                    'flag'       => $language['flag'],
                    'name'       => $language['name'],
                    'code'       => $language['code'],
                    'is_default' => $isDefault,
                    'is_rtl'     => $language['is_rtl'],
                    'status'     => $language['status'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $hasDefaultLanguage = $hasDefaultLanguage || $isDefault;
                $languagesChanged = true;
            }

            if ($languagesChanged) {
                \App\Models\Language::flushCache();
            }

            $componentIds = [];

            foreach ($baseline['components'] as $componentData) {
                $key = $componentData['component_key'];
                $existing = DB::table('page_components')
                    ->where('component_key', $key)
                    ->where('theme', $componentData['theme'])
                    ->orderBy('id')
                    ->first();

                if ($existing) {
                    $componentIds[$key] = (int) $existing->id;

                    continue;
                }

                $row = [
                    'component_icon'   => $componentData['component_icon'],
                    'component_name'   => $componentData['component_name'],
                    'component_key'    => $key,
                    'content_data'     => json_encode($componentData['content_data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'type'             => $componentData['type'],
                    'theme'            => $componentData['theme'],
                    'sort'             => $componentData['sort'],
                    'repeated_content' => $componentData['repeated_content'],
                    'is_active'        => $componentData['is_active'],
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ];

                if (Schema::hasColumn('page_components', 'is_modal')) {
                    $row['is_modal'] = false;
                }
                if (Schema::hasColumn('page_components', 'is_protected')) {
                    $row['is_protected'] = false;
                }

                $componentId = (int) DB::table('page_components')->insertGetId($row);
                $componentIds[$key] = $componentId;

                foreach ($componentData['repeated_items'] as $item) {
                    DB::table('page_component_repeated_contents')->insert([
                        'component_id' => $componentId,
                        'content_data' => json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }
            }

            $page = DB::table('pages')->where('slug', $baseline['page']['slug'])->first();
            if ($page) {
                return;
            }

            $orderedIds = [];
            foreach ($baseline['component_ids'] as $sourceId) {
                $componentKey = $this->componentKeyForSourceId($baseline, $sourceId);
                if (! isset($componentIds[$componentKey])) {
                    throw new RuntimeException("CMS baseline component is missing for source ID {$sourceId}.");
                }
                $orderedIds[] = $componentIds[$componentKey];
            }

            $pageData = [
                'title'         => json_encode($baseline['page']['title'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'slug'          => $baseline['page']['slug'],
                'component_ids' => json_encode($orderedIds, JSON_THROW_ON_ERROR),
                'type'          => $baseline['page']['type'],
                'is_active'     => $baseline['page']['is_active'],
                'created_at'    => now(),
                'updated_at'    => now(),
            ];

            if (Schema::hasColumn('pages', 'breadcrumb')) {
                $pageData['breadcrumb'] = $baseline['page']['breadcrumb'];
            }
            if (Schema::hasColumn('pages', 'is_breadcrumb')) {
                $pageData['is_breadcrumb'] = $baseline['page']['is_breadcrumb'];
            }

            DB::table('pages')->insert($pageData);
        });
    }

    private function componentKeyForSourceId(array $baseline, int|string $sourceId): string
    {
        foreach ($baseline['components'] as $component) {
            if ((string) $component['source_id'] === (string) $sourceId) {
                return $component['component_key'];
            }
        }

        throw new RuntimeException("CMS baseline has no component for source ID {$sourceId}.");
    }
}