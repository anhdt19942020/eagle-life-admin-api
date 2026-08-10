<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('printify_shop_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('printify_shop_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'printify_shop_id']);
        });

        DB::table('users')
            ->whereNotNull('printify_shop_id')
            ->orderBy('id')
            ->get()
            ->each(function ($user) {
                DB::table('printify_shop_user')->insert([
                    'user_id' => $user->id,
                    'printify_shop_id' => $user->printify_shop_id,
                    'is_default' => true,
                    'assigned_by' => $user->printify_shop_assigned_by,
                    'assigned_at' => $user->printify_shop_assigned_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        $dropCols = ['printify_shop_id', 'printify_shop_assigned_by', 'printify_shop_assigned_at'];

        if (DB::getDriverName() === 'sqlite') {
            $this->sqliteDropColumns('users', $dropCols);
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['printify_shop_assigned_by']);
                $table->dropColumn('printify_shop_assigned_at');
                $table->dropForeign(['printify_shop_id']);
                $table->dropUnique(['printify_shop_id']);
                $table->dropColumn('printify_shop_id');
                $table->dropColumn('printify_shop_assigned_by');
            });
        }
    }

    /**
     * SQLite cannot ALTER TABLE DROP COLUMN on columns involved in FK definitions.
     * Rebuild the table from PRAGMA metadata, omitting the specified columns.
     */
    private function sqliteDropColumns(string $table, array $columns): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        $pragmaCols = DB::select("PRAGMA table_info(\"{$table}\")");
        $keepCols = array_filter($pragmaCols, fn ($col) => ! in_array($col->name, $columns, true));

        $colDefs = [];
        foreach ($keepCols as $col) {
            $def = "\"{$col->name}\" {$col->type}";
            if ($col->pk) {
                $def .= ' primary key autoincrement';
            }
            if ($col->notnull && ! $col->pk) {
                $def .= ' not null';
            }
            if ($col->dflt_value !== null) {
                $def .= " default {$col->dflt_value}";
            }
            $colDefs[] = $def;
        }

        // Preserve non-FK indexes and unique constraints by recreating them after.
        $indexes = DB::select("PRAGMA index_list(\"{$table}\")");
        $indexSqls = [];
        foreach ($indexes as $idx) {
            if (str_starts_with($idx->name, 'sqlite_autoindex_')) {
                continue;
            }
            $idxCols = DB::select("PRAGMA index_info(\"{$idx->name}\")");
            $idxColNames = array_map(fn ($c) => $c->name, $idxCols);
            if (array_intersect($idxColNames, $columns)) {
                continue;
            }
            $quoted = implode(', ', array_map(fn ($c) => '"'.$c.'"', $idxColNames));
            $unique = $idx->unique ? 'UNIQUE ' : '';
            $indexSqls[] = "CREATE {$unique}INDEX \"{$idx->name}\" ON \"{$table}\" ({$quoted})";
        }

        $createSql = "CREATE TABLE \"{$table}__new\" (".implode(', ', $colDefs).')';
        $colNames = implode(', ', array_map(fn ($c) => '"'.$c->name.'"', $keepCols));

        DB::statement($createSql);
        DB::statement("INSERT INTO \"{$table}__new\" ({$colNames}) SELECT {$colNames} FROM \"{$table}\"");
        DB::statement("DROP TABLE \"{$table}\"");
        DB::statement("ALTER TABLE \"{$table}__new\" RENAME TO \"{$table}\"");

        foreach ($indexSqls as $sql) {
            DB::statement($sql);
        }

        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('printify_shop_id')
                ->nullable()
                ->unique()
                ->after('sales_group_id')
                ->constrained('printify_shops')
                ->nullOnDelete();
            $table->foreignId('printify_shop_assigned_by')
                ->nullable()
                ->after('printify_shop_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('printify_shop_assigned_at')->nullable()->after('printify_shop_assigned_by');
        });

        DB::table('printify_shop_user')
            ->where('is_default', true)
            ->orderBy('id')
            ->get()
            ->each(function ($pivot) {
                DB::table('users')->where('id', $pivot->user_id)->update([
                    'printify_shop_id' => $pivot->printify_shop_id,
                    'printify_shop_assigned_by' => $pivot->assigned_by,
                    'printify_shop_assigned_at' => $pivot->assigned_at,
                ]);
            });

        Schema::dropIfExists('printify_shop_user');
    }
};
