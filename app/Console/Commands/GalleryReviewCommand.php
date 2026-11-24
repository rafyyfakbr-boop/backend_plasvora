<?php

namespace App\Console\Commands;

use App\Models\GalleryWork;
use Illuminate\Console\Command;

class GalleryReviewCommand extends Command
{
    protected $signature = 'gallery:review
                            {id : ID karya galeri}
                            {decision : Keputusan moderasi (approve atau reject)}
                            {--reason= : Alasan penolakan (wajib jika decision=reject)}';

    protected $description = 'Setujui atau tolak karya galeri untuk moderasi tanpa panel admin web.';

    public function handle(): int
    {
        $id = $this->argument('id');
        if (! is_numeric($id) || (int) $id <= 0) {
            $this->error('ID karya tidak valid.');
            return self::FAILURE;
        }

        $decision = strtolower(trim((string) $this->argument('decision')));
        if (! in_array($decision, ['approve', 'reject'], true)) {
            $this->error('Keputusan tidak dikenal. Gunakan "approve" atau "reject".');
            return self::FAILURE;
        }

        $work = GalleryWork::with('author:id,name')->find((int) $id);
        if (! $work) {
            $this->error("Karya galeri dengan ID {$id} tidak ditemukan.");
            return self::FAILURE;
        }

        if ($decision === 'approve') {
            $work->status           = GalleryWork::STATUS_APPROVED;
            $work->published_at     = now();
            $work->reviewed_at      = now();
            $work->rejection_reason = null;
            $work->save();

            $this->info(sprintf(
                'Karya #%d ("%s" oleh %s) DISETUJUI dan kini tampil di Galeri publik.',
                $work->id,
                $work->title,
                $work->author?->name ?? ('User #' . $work->user_id)
            ));

            return self::SUCCESS;
        }

        $reason = trim((string) ($this->option('reason') ?? ''));
        if ($reason === '') {
            $this->error('Opsi --reason wajib diisi saat menolak karya.');
            return self::FAILURE;
        }

        $work->status           = GalleryWork::STATUS_REJECTED;
        $work->reviewed_at      = now();
        $work->published_at     = null;
        $work->rejection_reason = $reason;
        $work->save();

        $this->info(sprintf(
            'Karya #%d ("%s" oleh %s) DITOLAK. Alasan: %s',
            $work->id,
            $work->title,
            $work->author?->name ?? ('User #' . $work->user_id),
            $reason
        ));

        return self::SUCCESS;
    }
}
