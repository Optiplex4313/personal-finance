<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'instrument_type_enum') THEN
                    CREATE TYPE instrument_type_enum AS ENUM ('STOCK', 'ETF', 'FUND', 'CRYPTO');
                END IF;
            END
            $$;
        SQL);

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'market_provider_enum') THEN
                    CREATE TYPE market_provider_enum AS ENUM ('YAHOO', 'BINANCE');
                END IF;
            END
            $$;
        SQL);

        Schema::create('instruments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('symbol', 40);
            $table->string('isin', 12)->nullable();
            $table->string('name');
            $table->string('provider_symbol', 80);
            $table->string('currency', 20)->default('EUR');
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique(['symbol']);
        });

        DB::statement("ALTER TABLE instruments ADD COLUMN type instrument_type_enum NOT NULL");
        DB::statement("ALTER TABLE instruments ADD COLUMN provider market_provider_enum NOT NULL");

        Schema::create('price_bars', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('instrument_id');
            $table->date('date');
            $table->decimal('open', 20, 8);
            $table->decimal('high', 20, 8);
            $table->decimal('low', 20, 8);
            $table->decimal('close', 20, 8);
            $table->decimal('volume', 28, 8)->nullable();

            $table->foreign('instrument_id')
                ->references('id')
                ->on('instruments')
                ->onDelete('cascade');
            $table->unique(['instrument_id', 'date']);
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->uuid('instrument_id')->nullable()->after('bank_account_id');
            $table->decimal('units', 24, 8)->nullable()->after('current_value');

            $table->foreign('instrument_id')
                ->references('id')
                ->on('instruments')
                ->nullOnDelete();
        });

        Schema::table('crypto_accounts', function (Blueprint $table) {
            $table->uuid('instrument_id')->nullable()->after('id');

            $table->foreign('instrument_id')
                ->references('id')
                ->on('instruments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crypto_accounts', function (Blueprint $table) {
            $table->dropForeign(['instrument_id']);
            $table->dropColumn('instrument_id');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['instrument_id']);
            $table->dropColumn(['instrument_id', 'units']);
        });

        Schema::dropIfExists('price_bars');
        Schema::dropIfExists('instruments');

        DB::statement('DROP TYPE IF EXISTS market_provider_enum');
        DB::statement('DROP TYPE IF EXISTS instrument_type_enum');
    }
};
