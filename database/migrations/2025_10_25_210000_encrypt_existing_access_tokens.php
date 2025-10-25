<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Encrypts all existing access_tokens in accounts table.
     *
     * IMPORTANT: This migration MUST run AFTER Account model is updated with
     * encrypted cast, but BEFORE any code tries to read tokens with the new cast.
     */
    public function up(): void
    {
        // Use raw DB query to avoid Model casting (which would try to decrypt already plaintext tokens)
        $accounts = DB::table('accounts')
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->get();

        $encrypted = 0;
        $skipped = 0;

        foreach ($accounts as $account) {
            try {
                $token = $account->access_token;

                // Check if already encrypted (migration safety - can run multiple times)
                // Encrypted strings start with "eyJpdiI6" (base64 of {"iv":"...)
                if (str_starts_with($token, 'eyJpdiI6')) {
                    echo "Account {$account->account_id}: Token already encrypted, skipping\n";
                    $skipped++;
                    continue;
                }

                // Encrypt the plaintext token
                $encryptedToken = Crypt::encryptString($token);

                // Update using raw query (bypass Model to avoid double encryption)
                DB::table('accounts')
                    ->where('id', $account->id)
                    ->update(['access_token' => $encryptedToken]);

                echo "Account {$account->account_id}: Token encrypted successfully\n";
                $encrypted++;

            } catch (\Exception $e) {
                echo "Account {$account->account_id}: FAILED to encrypt token - {$e->getMessage()}\n";
                // Continue to next account instead of failing entire migration
            }
        }

        echo "\nEncryption complete:\n";
        echo "- Encrypted: {$encrypted}\n";
        echo "- Skipped (already encrypted): {$skipped}\n";
        echo "- Total processed: " . ($encrypted + $skipped) . "\n";
    }

    /**
     * Reverse the migrations.
     *
     * Decrypts all access_tokens back to plaintext.
     *
     * WARNING: This should only be used in development/testing!
     * In production, use with extreme caution.
     */
    public function down(): void
    {
        echo "WARNING: Decrypting access_tokens back to plaintext!\n";
        echo "This is a security risk in production. Continue? (y/n): ";

        // In automated environments, skip the prompt
        if (app()->environment('production')) {
            echo "ABORTED: Cannot decrypt tokens in production environment\n";
            return;
        }

        // Decrypt all tokens
        $accounts = DB::table('accounts')
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->get();

        $decrypted = 0;

        foreach ($accounts as $account) {
            try {
                $encryptedToken = $account->access_token;

                // Skip if not encrypted
                if (!str_starts_with($encryptedToken, 'eyJpdiI6')) {
                    continue;
                }

                // Decrypt
                $plainToken = Crypt::decryptString($encryptedToken);

                // Update
                DB::table('accounts')
                    ->where('id', $account->id)
                    ->update(['access_token' => $plainToken]);

                $decrypted++;

            } catch (\Exception $e) {
                echo "Account {$account->account_id}: FAILED to decrypt - {$e->getMessage()}\n";
            }
        }

        echo "Decrypted {$decrypted} tokens\n";
    }
};
