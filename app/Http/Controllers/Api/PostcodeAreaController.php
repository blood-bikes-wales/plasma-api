<?php

namespace App\Http\Controllers\Api;

use App\Authorization\Capability;
use App\Authorization\CapabilityMatrix;
use App\Authorization\RequestRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportOutboundPostcodeAreasRequest;
use App\Http\Requests\StoreOutboundPostcodeAreaRequest;
use App\Http\Requests\UpdateOutboundPostcodeAreaRequest;
use App\Http\Resources\OutboundPostcodeAreaResource;
use App\Models\OutboundPostcodeArea;
use App\Models\User;
use App\Services\PostcodeAreas\PostcodeAreaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PostcodeAreaController extends Controller
{
    public function __construct(
        private readonly PostcodeAreaService $postcodeAreas,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $includeInactive = $this->canManage($request)
            && filter_var($request->query('includeInactive', false), FILTER_VALIDATE_BOOLEAN);

        $areas = $this->postcodeAreas->list(
            is_string($request->query('q')) ? $request->query('q') : null,
            $includeInactive,
        );

        return OutboundPostcodeAreaResource::collection($areas)->response();
    }

    public function lookup(Request $request): JsonResponse
    {
        $postcode = $request->query('postcode');
        if (! is_string($postcode) || trim($postcode) === '') {
            throw ValidationException::withMessages([
                'postcode' => ['A postcode is required.'],
            ]);
        }

        $match = $this->postcodeAreas->lookup($postcode);
        if ($match === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $match]);
    }

    public function store(StoreOutboundPostcodeAreaRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $area = $this->postcodeAreas->create($user, $request->validated());

        return (new OutboundPostcodeAreaResource($area))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateOutboundPostcodeAreaRequest $request,
        OutboundPostcodeArea $outbound_postcode_area,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $area = $this->postcodeAreas->update($user, $outbound_postcode_area, $request->validated());

        return (new OutboundPostcodeAreaResource($area))->response();
    }

    public function import(ImportOutboundPostcodeAreasRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $file = $request->file('file');
        if ($file === null) {
            throw ValidationException::withMessages([
                'file' => ['A CSV or TSV file is required.'],
            ]);
        }

        $rows = $this->parseImportFile($file->getRealPath() ?: $file->getPathname());

        $result = $this->postcodeAreas->import($user, $rows);

        return response()->json($result);
    }

    private function canManage(Request $request): bool
    {
        $allowed = collect(CapabilityMatrix::roles(Capability::ManagePostcodeAreas))
            ->map(static fn ($role): string => $role->value);

        return collect(RequestRoles::expanded($request))
            ->map(static fn ($role): string => $role->value)
            ->intersect($allowed)
            ->isNotEmpty();
    }

    /**
     * @return list<array{
     *     outboundPostcode: string,
     *     areaCode: string,
     *     areaFullName: string,
     *     sortOrder?: int|null,
     *     active?: bool|null
     * }>
     */
    private function parseImportFile(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => ['Unable to read the uploaded file.'],
            ]);
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw ValidationException::withMessages([
                    'file' => ['The uploaded file is empty.'],
                ]);
            }

            $delimiter = str_contains($firstLine, "\t") ? "\t" : ',';
            rewind($handle);

            $header = fgetcsv($handle, null, $delimiter);
            if ($header === false) {
                throw ValidationException::withMessages([
                    'file' => ['The uploaded file is empty.'],
                ]);
            }

            $indexes = $this->headerIndexes($header);
            $rows = [];

            while (($row = fgetcsv($handle, null, $delimiter)) !== false) {
                $outbound = $this->cell($row, $indexes, ['outboundPostcode', 'outbound_postcode', 'postcode']);
                $areaCode = $this->cell($row, $indexes, ['areaCode', 'area_code']);
                $areaName = $this->cell($row, $indexes, ['areaFullName', 'area_full_name', 'fullName', 'full_name']);

                if ($outbound === null || $areaCode === null || $areaName === null) {
                    continue;
                }

                $sortRaw = $this->cell($row, $indexes, ['sortOrder', 'sort_order']);
                $activeRaw = $this->cell($row, $indexes, ['active']);

                $entry = [
                    'outboundPostcode' => $outbound,
                    'areaCode' => $areaCode,
                    'areaFullName' => $areaName,
                ];

                $entry['sortOrder'] = null;
                if ($sortRaw !== null && is_numeric($sortRaw)) {
                    $entry['sortOrder'] = (int) $sortRaw;
                }

                if ($activeRaw !== null) {
                    $entry['active'] = filter_var($activeRaw, FILTER_VALIDATE_BOOLEAN);
                }

                $rows[] = $entry;
            }

            if ($rows === []) {
                throw ValidationException::withMessages([
                    'file' => ['No valid mapping rows were found in the file.'],
                ]);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string|null>  $header
     * @return array<string, int>
     */
    private function headerIndexes(array $header): array
    {
        $indexes = [];
        foreach ($header as $i => $name) {
            if (! is_string($name)) {
                continue;
            }
            $indexes[trim($name)] = $i;
        }

        return $indexes;
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $indexes
     * @param  list<string>  $keys
     */
    private function cell(array $row, array $indexes, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $indexes)) {
                continue;
            }

            $value = $row[$indexes[$key]] ?? null;
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }
}
