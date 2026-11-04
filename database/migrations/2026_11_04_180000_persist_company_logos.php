<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->unique(['tenant_id', 'id'], 'company_logo_owner_unique'));
        Schema::create('company_logo_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('company_id');
            $t->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies');
            $t->unique(['tenant_id', 'company_id']);
            $t->string('mime', 40);
            $t->string('hash', 64);
            $t->longText('contents');
            $t->timestamps();
        });

        // Preserve existing uploads when their files are still available during the upgrade.
        $disk = Storage::disk('local');
        DB::table('companies')->whereNotNull('logo_path')->orderBy('id')->chunkById(100, function ($companies) use ($disk) {
            foreach ($companies as $company) {
                if (! $disk->exists($company->logo_path)) {
                    continue;
                }
                $bytes = $disk->get($company->logo_path);
                $info = @getimagesizefromstring($bytes);
                $mime = $info['mime'] ?? null;
                if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/bmp', 'image/x-ms-bmp'], true)) {
                    continue;
                }
                DB::table('company_logo_assets')->insert(['tenant_id' => $company->tenant_id, 'company_id' => $company->id, 'mime' => $mime, 'hash' => hash('sha256', $bytes), 'contents' => base64_encode($bytes), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_logo_assets');
        Schema::table('companies', fn (Blueprint $t) => $t->dropUnique('company_logo_owner_unique'));
    }
};
