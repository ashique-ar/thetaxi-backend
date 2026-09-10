<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inquiry\InquiryFormResource;
use App\Models\InquiryForm;
use App\Models\InquiryFormField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InquiryFormController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:inquiry-forms.view')->only(['index', 'show']);
        $this->middleware('permission:inquiry-forms.create')->only(['store']);
        $this->middleware('permission:inquiry-forms.edit')->only(['update']);
        $this->middleware('permission:inquiry-forms.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = InquiryForm::withInactive();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('slug', 'like', '%' . $request->search . '%');
            });
        }

        // Only apply is_active filter if explicitly set (not 'all')
        if ($request->filled('is_active') && $request->is_active !== 'all') {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        return InquiryFormResource::collection(
            $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 15)
        );
    }

    public function show(InquiryForm $inquiry_form): JsonResponse
    {
        $inquiry_form->load(['fields']);

        return response()->json([
            'status' => 'success',
            'data' => [
                'form' => new InquiryFormResource($inquiry_form),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateFormPayload($request);
        $fields = $data['fields'] ?? [];
        unset($data['fields']);

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        return DB::transaction(function () use ($data, $fields, $request) {
            $data['created_user_id'] = $request->user()?->id;
            $form = InquiryForm::create($data);

            if (!empty($fields)) {
                $this->syncFields($form, $fields);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Inquiry form created',
                'data' => [
                    'form' => new InquiryFormResource($form->load('fields')),
                ],
            ], 201);
        });
    }

    public function update(Request $request, InquiryForm $inquiry_form): JsonResponse
    {
        $data = $this->validateFormPayload($request, $inquiry_form->id);
        $fields = $data['fields'] ?? null;
        unset($data['fields']);

        if (array_key_exists('name', $data) && empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        return DB::transaction(function () use ($data, $fields, $request, $inquiry_form) {
            $data['updated_user_id'] = $request->user()?->id;
            $inquiry_form->update($data);

            if (is_array($fields)) {
                $this->syncFields($inquiry_form, $fields);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Inquiry form updated',
                'data' => [
                    'form' => new InquiryFormResource($inquiry_form->load('fields')),
                ],
            ]);
        });
    }

    public function destroy(InquiryForm $inquiry_form): JsonResponse
    {
        $inquiry_form->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Inquiry form deleted',
        ]);
    }

    /**
     * Validate request payload for form create/update.
     */
    protected function validateFormPayload(Request $request, ?string $formId = null): array
    {
        $slugRule = Rule::unique('inquiry_forms', 'slug');
        if ($formId) {
            $slugRule = $slugRule->ignore($formId);
        }

        return $request->validate([
            'name' => [$formId ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', $slugRule],
            'description' => ['nullable', 'string'],
            'submit_label' => ['nullable', 'string', 'max:255'],
            'success_message' => ['nullable', 'string'],
            'settings' => ['nullable', 'array'],
            'settings.submission_workflow' => ['nullable', Rule::in(['general', 'corporate', 'point_to_point'])],
            'is_active' => ['nullable', 'boolean'],
            'fields' => ['nullable', 'array'],
            'fields.*.id' => ['nullable', 'uuid'],
            'fields.*.name' => ['required_with:fields', 'string', 'max:255'],
            'fields.*.label' => ['required_with:fields', 'string', 'max:255'],
            'fields.*.type' => ['required_with:fields', 'string', 'max:50'],
            'fields.*.icon' => ['nullable', 'string', 'max:255'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.help_text' => ['nullable', 'string'],
            'fields.*.is_required' => ['nullable', 'boolean'],
            'fields.*.validation_rules' => ['nullable', 'string', 'max:255'],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.default_value' => ['nullable', 'string', 'max:255'],
            'fields.*.width' => ['nullable', 'string', 'max:50'],
            'fields.*.sort_order' => ['nullable', 'integer'],
            'fields.*.conditional_logic' => ['nullable', 'array'],
            'fields.*.is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * Sync fields for a form from payload.
     *
     * @param array<int, array<string, mixed>> $fields
     */
    protected function syncFields(InquiryForm $form, array $fields): void
    {
        $existing = $form->fields()->withInactive()->get()->keyBy('id');
        $seen = [];

        foreach ($fields as $index => $field) {
            $payload = $this->mapFieldPayload($field, $index);
            $fieldId = $field['id'] ?? null;

            if ($fieldId && $existing->has($fieldId)) {
                $existing->get($fieldId)->update($payload);
                $seen[] = $fieldId;
            } else {
                $created = $form->fields()->create($payload);
                $seen[] = $created->id;
            }
        }

        $toDelete = $existing->keys()->diff($seen);
        if ($toDelete->isNotEmpty()) {
            InquiryFormField::whereIn('id', $toDelete)->delete();
        }
    }

    /**
     * Map field payload to model attributes.
     */
    protected function mapFieldPayload(array $field, int $index): array
    {
        return [
            'name' => $field['name'],
            'label' => $field['label'],
            'type' => $field['type'],
            'icon' => $field['icon'] ?? null,
            'placeholder' => $field['placeholder'] ?? null,
            'help_text' => $field['help_text'] ?? null,
            'is_required' => $field['is_required'] ?? false,
            'validation_rules' => $field['validation_rules'] ?? null,
            'options' => $field['options'] ?? null,
            'default_value' => $field['default_value'] ?? null,
            'width' => $field['width'] ?? null,
            'sort_order' => $field['sort_order'] ?? ($index + 1),
            'conditional_logic' => $field['conditional_logic'] ?? null,
            'is_active' => $field['is_active'] ?? true,
        ];
    }
}
