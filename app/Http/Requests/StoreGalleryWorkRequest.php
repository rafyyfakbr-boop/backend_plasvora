<?php

namespace App\Http\Requests;

use App\Models\GalleryWork;
use App\Rules\AllowedVideoUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGalleryWorkRequest extends FormRequest
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

        if (! empty($merges)) {
            $this->merge($merges);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $minImages     = (int) config('gallery.min_images', 1);
        $maxImages     = (int) config('gallery.max_images', 5);
        $maxImageKb    = (int) config('gallery.max_image_kb', 4096);
        $maxVideoLinks = (int) config('gallery.max_video_links', 3);

        return [
            'title'               => ['required', 'string', 'min:3', 'max:120'],
            'difficulty'          => ['required', 'string', Rule::in(GalleryWork::DIFFICULTIES)],
            'materials'           => ['required', 'array', 'min:1', 'max:30'],
            'materials.*'         => ['required', 'string', 'min:1', 'max:100'],
            'tools'               => ['nullable', 'array', 'max:30'],
            'tools.*'             => ['required', 'string', 'min:1', 'max:100'],
            'steps'               => ['required', 'array', 'min:1', 'max:30'],
            'steps.*'             => ['required', 'string', 'min:1', 'max:1000'],
            'video_links'         => ['nullable', 'array', "max:{$maxVideoLinks}"],
            'video_links.*'       => ['required', 'string', 'max:500', new AllowedVideoUrl()],
            'images'              => ['required', 'array', "min:{$minImages}", "max:{$maxImages}"],
            'images.*'            => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', "max:{$maxImageKb}"],
            'ownership_confirmed' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'               => 'Nama karya wajib diisi.',
            'title.min'                    => 'Nama karya minimal 3 karakter.',
            'title.max'                    => 'Nama karya maksimal 120 karakter.',
            'difficulty.required'          => 'Tingkat kesulitan wajib dipilih.',
            'difficulty.in'                => 'Tingkat kesulitan harus salah satu dari: mudah, menengah, sulit.',
            'materials.required'           => 'Minimal satu bahan wajib diisi.',
            'materials.min'                => 'Minimal satu bahan wajib diisi.',
            'materials.max'                => 'Daftar bahan maksimal 30 item.',
            'materials.*.required'         => 'Setiap bahan tidak boleh kosong.',
            'materials.*.min'              => 'Setiap bahan tidak boleh kosong.',
            'materials.*.max'              => 'Setiap item bahan maksimal 100 karakter.',
            'tools.max'                    => 'Daftar alat maksimal 30 item.',
            'tools.*.required'             => 'Setiap alat tidak boleh kosong.',
            'tools.*.min'                  => 'Setiap alat tidak boleh kosong.',
            'tools.*.max'                  => 'Setiap item alat maksimal 100 karakter.',
            'steps.required'               => 'Minimal satu langkah pembuatan wajib diisi.',
            'steps.min'                    => 'Minimal satu langkah pembuatan wajib diisi.',
            'steps.max'                    => 'Langkah pembuatan maksimal 30 langkah.',
            'steps.*.required'             => 'Setiap langkah pembuatan tidak boleh kosong.',
            'steps.*.min'                  => 'Setiap langkah pembuatan tidak boleh kosong.',
            'steps.*.max'                  => 'Setiap langkah pembuatan maksimal 1000 karakter.',
            'video_links.max'              => 'Maksimal 3 tautan video tutorial.',
            'video_links.*.required'       => 'Tautan video tidak boleh kosong.',
            'images.required'              => 'Minimal 1 foto karya wajib diunggah.',
            'images.min'                   => 'Minimal 1 foto karya wajib diunggah.',
            'images.max'                   => 'Maksimal 5 foto karya.',
            'images.*.image'               => 'Setiap file harus berupa gambar yang valid.',
            'images.*.mimes'               => 'Format gambar hanya diperbolehkan jpg, jpeg, png, atau webp.',
            'images.*.max'                 => 'Ukuran setiap foto maksimal 4 MB.',
            'ownership_confirmed.required' => 'Anda wajib menyetujui pernyataan kepemilikan karya.',
            'ownership_confirmed.accepted' => 'Anda wajib menyetujui pernyataan kepemilikan karya.',
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
