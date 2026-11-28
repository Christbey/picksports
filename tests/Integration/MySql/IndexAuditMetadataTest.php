<?php

use App\Services\Database\IndexAuditService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

it('preserves actual visibility direction and expression distinctions in index advice', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $table = 'audit_index_'.bin2hex(random_bytes(6));
    try {
        DB::statement("CREATE TABLE `{$table}` (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY, home_score INT NOT NULL, away_score INT NOT NULL,
            KEY idx_a (home_score, away_score),
            KEY idx_b_invisible (home_score, away_score) INVISIBLE,
            KEY idx_c_desc (home_score, away_score DESC),
            KEY idx_d_expression ((home_score + 1)),
            KEY idx_e_expression ((away_score + 1)),
            KEY idx_f_duplicate (home_score, away_score)
        ) ENGINE=InnoDB");
        $findings = collect(app(IndexAuditService::class)->inspect(tables: [$table]));
        $redundant = $findings->whereIn('kind', ['duplicate', 'left_prefix'])->values();
        expect($redundant)->toHaveCount(1)
            ->and($redundant[0]['index'])->toBe('idx_f_duplicate')
            ->and($redundant[0]['covered_by'])->toBe('idx_a')
            ->and($findings->where('kind', 'not_assessed')->pluck('index')->sort()->values()->all())
            ->toBe(['idx_b_invisible', 'idx_d_expression', 'idx_e_expression']);
    } finally {
        DB::statement("DROP TABLE IF EXISTS `{$table}`");
    }
});
