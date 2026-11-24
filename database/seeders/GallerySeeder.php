<?php

namespace Database\Seeders;

use App\Models\GalleryWork;
use App\Models\User;
use Illuminate\Database\Seeder;

class GallerySeeder extends Seeder
{
    /**
     * Jalankan secara manual jika ingin mengisi 3 karya demo:
     * php artisan db:seed --class=GallerySeeder
     */
    public function run(): void
    {
        $author = User::first() ?? User::factory()->create([
            'name'  => 'Demo Kreator Plasvora',
            'email' => 'demo.galeri@plasvora.local',
        ]);

        $demos = [
            [
                'title'      => '[Demo] Pot Hidroponik Siram Mandiri dari Botol PET',
                'difficulty' => GalleryWork::DIFFICULTY_MUDAH,
                'materials'  => [
                    '1 botol air mineral PET 1,5 liter bekas',
                    'Sumbu kain flanel sepanjang 15 cm',
                    'Media tanam sekam bakar dan cocopeat',
                    'Air nutrisi tanaman secukupnya',
                ],
                'tools'      => [
                    'Cutter atau gunting tajam',
                    'Spidol untuk penanda garis potong',
                    'Amplas halus',
                ],
                'steps'      => [
                    'Cuci bersih botol PET bekas dan lepaskan seluruh label serta sisa lemnya.',
                    'Tandai sepertiga bagian atas botol dengan spidol lalu potong rapi menjadi dua bagian.',
                    'Haluskan tepi potongan plastik menggunakan amplas halus agar tidak melukai tangan.',
                    'Lubangi tutup botol atau lepas tutupnya, lalu selipkan sumbu kain flanel melalui leher botol.',
                    'Balik bagian atas botol ke dalam bagian bawah yang sudah diisi air, lalu isi media tanam dan bibit.',
                ],
                'video_links' => [
                    'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                ],
            ],
            [
                'title'      => '[Demo] Tatakan Gelas Mozaik Tutup Botol HDPE',
                'difficulty' => GalleryWork::DIFFICULTY_MENENGAH,
                'materials'  => [
                    '25 tutup botol plastik HDPE warna-warni (kode #2)',
                    'Kertas roti tahan panas (baking paper)',
                ],
                'tools'      => [
                    'Setrika atau mesin press panas suhu terkontrol',
                    'Cetakan silikon bundar diameter 10 cm',
                    'Sarung tangan tahan panas',
                    'Amplas grit 240 dan 400',
                ],
                'steps'      => [
                    'Pisahkan tutup botol khusus berkode #2 (HDPE), cuci dengan sabun hingga bersih, lalu keringkan total.',
                    'Potong kecil-kecil tutup botol agar mudah meleleh merata dan susun pola warna di atas kertas roti.',
                    'Tutup bagian atas dengan lembar kertas roti kedua, lalu panaskan perlahan dengan setrika di ruang berventilasi baik hingga meleleh menyatu.',
                    'Tekan pelan dengan beban datar hingga dingin dan mengeras sempurna.',
                    'Bentuk lingkaran tatakan gelas lalu haluskan seluruh tepian menggunakan amplas halus.',
                ],
                'video_links' => [],
            ],
            [
                'title'      => '[Demo] Organizer Meja Modular dari Jerigen HDPE',
                'difficulty' => GalleryWork::DIFFICULTY_SULIT,
                'materials'  => [
                    '2 jerigen bekas detergen HDPE 2 liter',
                    '4 buah mur dan baut mini M4',
                    'Tali katun atau pegangan bekas untuk gantungan',
                ],
                'tools'      => [
                    'Penggaris besi dan spidol permanen',
                    'Gergaji tangan mini atau cutter heavy-duty',
                    'Bor tangan dengan mata bor 4 mm',
                    'Amplas kasar dan halus',
                ],
                'steps'      => [
                    'Bilas jerigen bekas berulang kali hingga bebas sisa detergen dan keringkan di tempat teduh.',
                    'Gambar pola potong miring setinggi 12 cm pada bagian depan dan 18 cm pada bagian belakang kedua jerigen.',
                    'Potong jerigen mengikuti garis pola secara hati-hati lalu rapikan tepiannya dengan amplas.',
                    'Tandai titik sambungan pada sisi samping kedua wadah dan lubangi menggunakan bor 4 mm.',
                    'Gabungkan kedua kompartemen dengan mur dan baut M4 hingga kokoh, lalu bersihkan kembali sebelum digunakan.',
                ],
                'video_links' => [],
            ],
        ];

        foreach ($demos as $index => $demo) {
            $exists = GalleryWork::where('title', $demo['title'])->exists();
            if ($exists) {
                continue;
            }

            $work = new GalleryWork();
            $work->user_id                = $author->id;
            $work->title                  = $demo['title'];
            $work->difficulty             = $demo['difficulty'];
            $work->materials              = $demo['materials'];
            $work->tools                  = $demo['tools'];
            $work->steps                  = $demo['steps'];
            $work->video_links            = $demo['video_links'];
            $work->status                 = GalleryWork::STATUS_APPROVED;
            $work->published_at           = now()->subHours(3 - $index);
            $work->reviewed_at            = now()->subHours(3 - $index);
            $work->ownership_confirmed_at = now()->subHours(4 - $index);
            $work->likes_count            = 0;
            $work->save();
        }
    }
}
