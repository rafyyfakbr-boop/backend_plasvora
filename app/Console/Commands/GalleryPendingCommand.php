<?php

namespace App\Console\Commands;

use App\Models\GalleryWork;
use Illuminate\Console\Command;

class GalleryPendingCommand extends Command
{
    protected $signature = 'gallery:pending';

    protected $description = 'Tampilkan daftar karya galeri yang menunggu verifikasi (pending).';

    public function handle(): int
    {
        $pending = GalleryWork::query()
            ->with('author:id,name')
            ->where('status', GalleryWork::STATUS_PENDING)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Tidak ada karya galeri yang menunggu verifikasi.');
            return self::SUCCESS;
        }

        $rows = $pending->map(fn (GalleryWork $work) => [
            $work->id,
            $work->title,
            $work->author?->name ?? ('User #' . $work->user_id),
            $work->created_at?->toDateTimeString() ?? '-',
        ])->all();

        $this->table(['ID', 'Judul Karya', 'Penulis', 'Dibuat Pada'], $rows);
        $this->info(sprintf('Total pending: %d karya.', count($rows)));

        return self::SUCCESS;
    }
}
