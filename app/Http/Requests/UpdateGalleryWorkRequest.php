<?php

namespace App\Http\Requests;

use App\Models\GalleryWork;
use App\Rules\AllowedVideoUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGalleryWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merges = [];

        if ($this->has('title') && is_string($this->input('title'))) {
            $merges['title'] = $this->sanitizeSingleLine($this->input('title'));
        }

        if ($this->has('difficulty') && is_string($this->input('difficulty'))) {
            $merges['difficulty'] = strtolower(trim($this->input('difficulty')));
        }

        foreach (['materials', 'tools', 'video_links'] as $field) {
            if ($this->has($field) && is_array($this->input($field))) {
                $merges[$field] = array_values(array_map(
                    fn ($item) => is_string($item) ? $this->sanitizeSingleLine($item) : $item,
                    $this->input($field)
                ));
            }
        }

        if ($this->has('steps') && is_array($this->input('steps'))) {
            $merges['steps'] = array_values(array_map(
                fn ($item) => is_string($item) ? $this->sanitizeMultiLine($item) : $item,
                $this->input('steps')
            ));
        }

        foreach (['clear_tools', 'clear_video_links'] as $flag) {
            if ($this->has($flag) && is_string($this->input($flag))) {
                $parsed = filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed !== null) {
                    $merges[$flag] = $parsed;
                }
            }
        }

        if (! empty($merges)) {
            $this->merge($merges);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $maxImages     = (int) config('gallery.max_images', 5);
        $maxImageKb    = (int) config('gallery.max_image_kb', 4096);
        $maxVideoLinks = (int) config('gallery.max_video_links', 3);

        return [
            'title'               => ['sometimes', 'required', 'string', 'min:3', 'max:120'],
            'difficulty'          => ['sometimes', 'required', 'string', Rule::in(GalleryWork::DIFFICULTIES)],
            'materials'           => ['sometimes', 'required', 'array', 'min:1', 'max:30'],
            'materials.*'         => ['required', 'string', 'min:1', 'max:100'],
            'tools'               => ['sometimes', 'nullable', 'array', 'max:30'],
            'tools.*'             => ['required', 'string', 'min:1', 'max:100'],
            'clear_tools'         => ['sometimes', 'boolean'],
            'steps'               => ['sometimes', 'required', 'array', 'min:1', 'max:30'],
            'steps.*'             => ['required', 'string', 'min:1', 'max:1000'],
            'video_links'         => ['sometimes', 'nullable', 'array', "max:{$maxVideoLinks}"],
            'video_links.*'       => ['required', 'string', 'max:500', new AllowedVideoUrl()],
            'clear_video_links'   => ['sometimes', 'boolean'],
            'images'              => ['sometimes', 'array', "max:{$maxImages}"],
            'images.*'            => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', "max:{$maxImageKb}"],
            'remove_image_ids'    => ['sometimes', 'array'],
            'remove_image_ids.*'  => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'         => 'Nama karya wajib diisi.',
            'title.min'              => 'Nama karya minimal 3 karakter.',
            'title.max'              => 'Nama karya maksimal 120 karakter.',
            'difficulty.in'          => 'Tingkat kesulitan harus salah satu dari: mudah, menengah, sulit.',
            'materials.min'          => 'Minimal satu bahan wajib diisi.',
            'materials.max'          => 'Daftar bahan maksimal 30 item.',
            'materials.*.required'   => 'Setiap bahan tidak boleh kosong.',
            'materials.*.max'        => 'Setiap item bahan maksimal 100 karakter.',
            'tools.max'              => 'Daftar alat maksimal 30 item.',
            'tools.*.required'       => 'Setiap alat tidak boleh kosong.',
            'tools.*.max'            => 'Setiap item alat maksimal 100 karakter.',
            'steps.min'              => 'Minimal satu langkah pembuatan wajib diisi.',
            'steps.max'              => 'Langkah pembuatan maksimal 30 langkah.',
            'steps.*.required'       => 'Setiap langkah pembuatan tidak boleh kosong.',
            'steps.*.max'            => 'Setiap langkah pembuatan maksimal 1000 karakter.',
            'video_links.max'        => 'Maksimal 3 tautan video tutorial.',
            'video_links.*.required' => 'Tautan video tidak boleh kosong.',
            'images.max'             => 'Maksimal 5 foto karya.',
            'images.*.image'         => 'Setiap file harus berupa gambar yang valid.',
            'images.*.mimes'         => 'Format gambar hanya diperbolehkan jpg, jpeg, png, atau webp.',
            'images.*.max'           => 'Ukuran setiap foto maksimal 4 MB.',
        ];
    }

    private function sanitizeSingleLine(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        return trim($clean ?? $value);
    }

    private function sanitizeMultiLine(string $value): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value);
        return trim($clean ?? $value);
    }
}
