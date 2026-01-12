<?php

declare(strict_types=1);

namespace LogScope\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LogScope\Models\FilterPreset;

class PresetController extends Controller
{
    /**
     * List all presets.
     */
    public function index(): JsonResponse
    {
        $presets = FilterPreset::ordered()->get();

        return response()->json(['data' => $presets]);
    }

    /**
     * Store a new preset.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'filters' => 'required|array',
            'filters.levels' => 'nullable|array',
            'filters.exclude_levels' => 'nullable|array',
            'filters.channels' => 'nullable|array',
            'filters.environments' => 'nullable|array',
            'filters.search' => 'nullable|string',
            'filters.from' => 'nullable|date',
            'filters.to' => 'nullable|date',
        ]);

        $preset = FilterPreset::createFromFilters(
            $validated['name'],
            $validated['filters'],
            $validated['description'] ?? null
        );

        return response()->json(['data' => $preset], 201);
    }

    /**
     * Update a preset.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $preset = FilterPreset::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string|max:500',
            'filters' => 'sometimes|required|array',
        ]);

        $preset->update($validated);

        return response()->json(['data' => $preset]);
    }

    /**
     * Delete a preset.
     */
    public function destroy(string $id): JsonResponse
    {
        $preset = FilterPreset::findOrFail($id);
        $preset->delete();

        return response()->json(['message' => 'Preset deleted']);
    }

    /**
     * Set a preset as default.
     */
    public function setDefault(string $id): JsonResponse
    {
        $preset = FilterPreset::findOrFail($id);
        $preset->setAsDefault();

        return response()->json(['data' => $preset]);
    }

    /**
     * Reorder presets.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order' => 'required|array',
            'order.*' => 'string',
        ]);

        foreach ($validated['order'] as $index => $id) {
            FilterPreset::where('id', $id)->update(['sort_order' => $index + 1]);
        }

        return response()->json(['message' => 'Presets reordered']);
    }
}
