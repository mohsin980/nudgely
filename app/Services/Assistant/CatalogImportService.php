<?php

namespace App\Services\Assistant;

use App\Models\CatalogItem;
use App\Models\User;
use App\Support\CsvFile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Imports catalog items from a CSV file. Columns are found by their header ("Name", "Category", "Price",
 * "Details", "Available"); only Name is required. An item whose name is already in the catalog is updated,
 * so a business can re-upload its price list to change prices.
 */
class CatalogImportService
{
    public const MAX_ROWS = 2000;

    public const MAX_BYTES = 2 * 1024 * 1024;

    /** @var array<string, list<string>> */
    public const COLUMNS = [
        'name' => ['name', 'item', 'item_name', 'product', 'product_name', 'service', 'service_name', 'title'],
        'category' => ['category', 'type', 'group'],
        'price' => ['price', 'cost', 'rate', 'amount'],
        'details' => ['details', 'description', 'notes', 'info'],
        'is_available' => ['available', 'is_available', 'availability', 'in_stock', 'status'],
    ];

    public function __construct(private readonly AssistantKnowledgeService $knowledge) {}

    /**
     * @return array{created: int, updated: int, failed: list<array{row: int, message: string}>}
     *
     * @throws AuthorizationException
     * @throws RuntimeException with a message for the person importing
     */
    public function import(User $actor, string $path): array
    {
        $organization = $this->knowledge->organizationOf($actor);
        ['headers' => $headers, 'rows' => $rows] = CsvFile::read($path, self::MAX_BYTES, self::MAX_ROWS, 'items');
        $columns = $this->columns($headers);

        if ($columns['name'] === null) {
            throw new RuntimeException('The file needs a "Name" column. Download the sample file to see the columns.');
        }

        $existing = $organization->catalogItems()->get()->keyBy(fn ($item) => mb_strtolower($item->name));
        $room = AssistantKnowledgeService::MAX_CATALOG_ITEMS - $existing->count();
        $result = ['created' => 0, 'updated' => 0, 'failed' => []];

        DB::transaction(function () use ($rows, $columns, $existing, $organization, &$room, &$result) {
            foreach ($rows as $number => $cells) {
                $cell = fn (string $field) => $columns[$field] === null ? '' : ($cells[$columns[$field]] ?? '');

                try {
                    $data = $this->knowledge->validateCatalogItem([
                        'name' => $cell('name'),
                        'category' => $cell('category'),
                        'price' => $cell('price'),
                        'details' => $cell('details'),
                        // Blank means available; only clear "no"-like values mark an item unavailable.
                        'is_available' => ! in_array(strtolower(trim($cell('is_available'))), ['no', 'n', 'false', '0', 'sold', 'sold out', 'unavailable', 'out of stock'], true),
                    ]);
                } catch (ValidationException $e) {
                    $result['failed'][] = ['row' => $number + 1, 'message' => collect($e->errors())->flatten()->first()]; // +1: the header is line 1

                    continue;
                }

                $item = $existing->get(mb_strtolower($data['name']));

                if ($item === null && $room <= 0) {
                    $result['failed'][] = ['row' => $number + 1, 'message' => 'Your catalog is full ('.number_format(AssistantKnowledgeService::MAX_CATALOG_ITEMS).' items).'];

                    continue;
                }

                $isNew = $item === null;
                $item ??= new CatalogItem;
                $this->knowledge->fillCatalogItem($item, $organization, $data);
                $existing->put(mb_strtolower($data['name']), $item);

                if ($isNew) {
                    $room--;
                    $result['created']++;
                } else {
                    $result['updated']++;
                }
            }
        });

        return $result;
    }

    /**
     * The column index of each field, or null.
     *
     * @param  list<string>  $headers
     * @return array<string, ?int>
     */
    public function columns(array $headers): array
    {
        $normalized = array_map(CsvFile::key(...), $headers);
        $map = [];

        foreach (self::COLUMNS as $field => $aliases) {
            $map[$field] = null;

            foreach ($aliases as $alias) {
                if (($index = array_search(CsvFile::key($alias), $normalized, true)) !== false) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    public static function sample(): string
    {
        return "Name,Category,Price,Details,Available\n"
            ."\"5 Marla plot, DHA Phase 5\",Plots,1.5 Cr,\"Corner, near park. Possession ready.\",yes\n"
            ."AC service,Maintenance,\$120 per visit,\"Cleaning, gas check and filter change.\",yes\n";
    }
}
