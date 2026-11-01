<?php

namespace App\Notifications;

use App\Models\User;
use Spatie\Backup\Notifications\Notifiable;
use Throwable;

/**
 * Who backup failure alerts go to: the dedicated BACKUP_NOTIFY_EMAIL
 * when one is configured, otherwise the owner account's current email
 * (the database is the source of truth — the Profile page can change it
 * at any time, so nothing is copied into .env). No resolvable address
 * means alerts are silently skipped.
 */
class BackupNotifiable extends Notifiable
{
    /**
     * Stand-in used in config/backup.php when no dedicated alert address
     * is set: a syntactically valid email (the package's config object
     * rejects anything else) that nobody will ever actually configure.
     */
    public const OWNER_FROM_DATABASE = 'owner-account@unset.invalid';

    /**
     * @return string|array{int, string}
     */
    public function routeNotificationForMail(): string|array
    {
        // The parent contract doesn't allow null; an empty route means
        // the mail channel skips the notification.
        return $this->email() ?? [];
    }

    /**
     * The address alerts would go to right now, or null.
     */
    public function email(): ?string
    {
        $configured = config('backup.notifications.mail.to');

        if (is_string($configured)
            && $configured !== self::OWNER_FROM_DATABASE
            && filter_var($configured, FILTER_VALIDATE_EMAIL) !== false) {
            return $configured;
        }

        try {
            $email = User::query()->value('email');
        } catch (Throwable) {
            // Before installation there is no users table to ask.
            return null;
        }

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
