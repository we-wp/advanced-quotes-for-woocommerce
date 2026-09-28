<?php

namespace WeWP\AdvancedQuotes;

use RuntimeException;
use Throwable;

/**
 * Quote persistence. Quotes are mutable working records; sent revisions are immutable, hashed snapshots.
 */
final class Store
{
    /** Statuses a merchant can still edit and send. */
    public const EDITABLE = ['requested', 'draft', 'sent', 'declined'];

    /** Statuses that are final for the current revision. */
    public const FINAL = ['accepted', 'paid', 'cancelled'];

    public function table(string $name): string
    {
        global $wpdb;
        if (! in_array($name, ['quotes', 'revisions', 'events', 'counter'], true)) {
            throw new RuntimeException('Invalid table.');
        }

        return $wpdb->prefix.'wewp_aq_'.$name;
    }

    public function install(): void
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE '.$this->table('quotes')." (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            number varchar(40) NOT NULL,
            status varchar(20) NOT NULL,
            source varchar(20) NOT NULL,
            customer_id bigint unsigned NOT NULL DEFAULT 0,
            email varchar(190) NOT NULL DEFAULT '',
            customer_name varchar(200) NOT NULL DEFAULT '',
            company varchar(200) NOT NULL DEFAULT '',
            currency char(3) NOT NULL DEFAULT '',
            total varchar(40) NOT NULL DEFAULT '0',
            revision int unsigned NOT NULL DEFAULT 0,
            expires_on date DEFAULT NULL,
            order_id bigint unsigned NOT NULL DEFAULT 0,
            access_secret char(32) NOT NULL,
            lock_token char(32) DEFAULT NULL,
            locked_at datetime DEFAULT NULL,
            draft longtext NOT NULL,
            request longtext DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY number (number),
            KEY status (status),
            KEY email (email),
            KEY customer_id (customer_id),
            KEY order_id (order_id)
        ) ENGINE=InnoDB $charset;");
        dbDelta('CREATE TABLE '.$this->table('revisions')." (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            quote_id bigint unsigned NOT NULL,
            revision int unsigned NOT NULL,
            snapshot longtext NOT NULL,
            snapshot_hash char(64) NOT NULL,
            pdf longblob DEFAULT NULL,
            pdf_hash char(64) DEFAULT NULL,
            sent_at datetime NOT NULL,
            sent_by bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY quote_revision (quote_id,revision)
        ) ENGINE=InnoDB $charset;");
        dbDelta('CREATE TABLE '.$this->table('events')." (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            quote_id bigint unsigned NOT NULL,
            revision int unsigned NOT NULL DEFAULT 0,
            kind varchar(30) NOT NULL,
            actor_id bigint unsigned NOT NULL DEFAULT 0,
            detail varchar(500) NOT NULL DEFAULT '',
            happened_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY quote_id (quote_id)
        ) ENGINE=InnoDB $charset;");
        dbDelta('CREATE TABLE '.$this->table('counter')." (
            id bigint unsigned NOT NULL,
            next_number bigint unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY  (id)
        ) ENGINE=InnoDB $charset;");
        $this->query($wpdb->prepare('INSERT IGNORE INTO %i (id) VALUES (1)', $this->table('counter')));
        update_option('wewp_aq_schema', Plugin::SCHEMA, false);
        foreach (['administrator', 'shop_manager'] as $role) {
            get_role($role)?->add_cap('manage_wewp_quotes');
        }
    }

    private function query(string $sql): int|bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Callers pass static transaction commands or wpdb-prepared SQL only.
        $result = $wpdb->query($sql);
        if ($result === false) {
            throw new RuntimeException(__('Quote storage is unavailable. Nothing was changed.', 'advanced-quotes-for-woocommerce'));
        }

        return $result;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function json(array $value): string
    {
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json) || strlen($json) > 1048576) {
            throw new RuntimeException(__('The quote is too large to save.', 'advanced-quotes-for-woocommerce'));
        }

        return $json;
    }

    private function hydrate(?array $row): ?array
    {
        if (! $row) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['customer_id'] = (int) $row['customer_id'];
        $row['revision'] = (int) $row['revision'];
        $row['order_id'] = (int) $row['order_id'];
        $row['draft'] = json_decode((string) $row['draft'], true) ?: [];
        $row['request'] = $row['request'] ? (json_decode((string) $row['request'], true) ?: null) : null;

        return $row;
    }

    /**
     * Create a quote and allocate its number from the locked counter row.
     */
    public function create(array $fields, array $draft, ?array $request, string $prefix): array
    {
        global $wpdb;
        if (! in_array($fields['status'] ?? '', ['requested', 'draft'], true)) {
            throw new RuntimeException('New quotes start as requested or draft.');
        }
        $draftJson = $this->json($draft);
        $requestJson = $request === null ? null : $this->json($request);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $this->query('START TRANSACTION');
                $next = $wpdb->get_var($wpdb->prepare('SELECT next_number FROM %i WHERE id=1 FOR UPDATE', $this->table('counter')));
                if ($next === null) {
                    throw new RuntimeException(__('Quote numbering is unavailable. Deactivate and activate the plugin to repair it.', 'advanced-quotes-for-woocommerce'));
                }
                $number = $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
                $ok = $wpdb->insert($this->table('quotes'), [
                    'number' => $number,
                    'status' => $fields['status'],
                    'source' => $fields['source'] ?? 'admin',
                    'customer_id' => (int) ($fields['customer_id'] ?? 0),
                    'email' => strtolower((string) ($fields['email'] ?? '')),
                    'customer_name' => mb_substr((string) ($fields['customer_name'] ?? ''), 0, 200),
                    'company' => mb_substr((string) ($fields['company'] ?? ''), 0, 200),
                    'currency' => (string) ($fields['currency'] ?? get_woocommerce_currency()),
                    'total' => (string) ($fields['total'] ?? '0'),
                    'access_secret' => bin2hex(random_bytes(16)),
                    'draft' => $draftJson,
                    'request' => $requestJson,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                if (! $ok) {
                    throw new RuntimeException(__('Quote storage is unavailable. Nothing was changed.', 'advanced-quotes-for-woocommerce'));
                }
                $id = (int) $wpdb->insert_id;
                $this->query($wpdb->prepare('UPDATE %i SET next_number=next_number+1 WHERE id=1', $this->table('counter')));
                $this->insertEvent($id, 0, $fields['status'] === 'requested' ? 'requested' : 'created', (int) ($fields['actor_id'] ?? get_current_user_id()), '');
                $this->query('COMMIT');

                return $this->find($id);
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                if ($attempt === 2 || ! str_contains($e->getMessage(), 'storage')) {
                    throw $e;
                }
                usleep(20000 * ($attempt + 1));
            }
        }
        throw new RuntimeException(__('The quote could not be created.', 'advanced-quotes-for-woocommerce'));
    }

    public function find(int $id): ?array
    {
        global $wpdb;

        return $this->hydrate($wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $this->table('quotes'), $id), ARRAY_A));
    }

    public function findByOrder(int $orderId): ?array
    {
        global $wpdb;
        if ($orderId <= 0) {
            return null;
        }

        return $this->hydrate($wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id=%d ORDER BY id DESC LIMIT 1', $this->table('quotes'), $orderId), ARRAY_A));
    }

    /**
     * Save the working copy. Once a quote has been sent, its customer, email and total columns keep the
     * values of the last sent revision, because access checks and My Account read them. Only send() changes them.
     */
    public function saveDraft(int $id, array $draft, array $fields, bool $allowAccepted = false): void
    {
        global $wpdb;
        $statuses = $allowAccepted ? array_merge(self::EDITABLE, ['accepted']) : self::EDITABLE;
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The IN () placeholder list is generated from a fixed status allowlist.
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET draft=%s, customer_id=IF(revision=0, %d, customer_id), email=IF(revision=0, %s, email), customer_name=IF(revision=0, %s, customer_name), company=IF(revision=0, %s, company), currency=IF(revision=0, %s, currency), total=IF(revision=0, %s, total), updated_at=%s WHERE id=%d AND status IN ('.$placeholders.')',
            $this->table('quotes'),
            $this->json($draft),
            (int) $fields['customer_id'],
            strtolower((string) $fields['email']),
            mb_substr((string) $fields['customer_name'], 0, 200),
            mb_substr((string) $fields['company'], 0, 200),
            (string) $fields['currency'],
            (string) $fields['total'],
            $this->now(),
            $id,
            ...$statuses
        ));
        // phpcs:enable
        if ($updated === false) {
            throw new RuntimeException(__('Quote storage is unavailable. Nothing was changed.', 'advanced-quotes-for-woocommerce'));
        }
        if ($updated === 0 && ! $this->find($id)) {
            throw new RuntimeException(__('The quote no longer exists.', 'advanced-quotes-for-woocommerce'));
        }
        if ($updated === 0) {
            $current = $this->find($id);
            if (! in_array($current['status'], $statuses, true)) {
                throw new RuntimeException(__('This quote can no longer be edited.', 'advanced-quotes-for-woocommerce'));
            }
        }
    }

    /**
     * Record a new immutable revision and mark the quote as sent.
     *
     * @param callable(array $quote, int $revision): array $build Builds the snapshot for the locked quote and next revision.
     */
    public function send(int $id, callable $build, bool $allowAccepted = false): array
    {
        global $wpdb;
        try {
            $this->query('START TRANSACTION');
            $quote = $this->hydrate($wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d FOR UPDATE', $this->table('quotes'), $id), ARRAY_A));
            if (! $quote) {
                throw new RuntimeException(__('The quote no longer exists.', 'advanced-quotes-for-woocommerce'));
            }
            $allowed = $allowAccepted ? array_merge(self::EDITABLE, ['accepted']) : self::EDITABLE;
            if (! in_array($quote['status'], $allowed, true)) {
                throw new RuntimeException(__('This quote can no longer be sent.', 'advanced-quotes-for-woocommerce'));
            }
            $revision = $quote['revision'] + 1;
            $snapshot = $build($quote, $revision);
            $json = $this->json($snapshot);
            $ok = $wpdb->insert($this->table('revisions'), [
                'quote_id' => $id,
                'revision' => $revision,
                'snapshot' => $json,
                'snapshot_hash' => hash('sha256', $json),
                'sent_at' => $this->now(),
                'sent_by' => get_current_user_id(),
            ]);
            if (! $ok) {
                throw new RuntimeException(__('Quote storage is unavailable. Nothing was changed.', 'advanced-quotes-for-woocommerce'));
            }
            $email = strtolower($snapshot['customer']['email']);
            $customerId = (int) ($snapshot['customer_id'] ?? 0);
            // A previous revision went to someone else: links sent earlier must not show this one.
            $recipientChanged = $quote['revision'] > 0 && ($quote['email'] !== $email || $quote['customer_id'] !== $customerId);
            $this->query($wpdb->prepare(
                "UPDATE %i SET status='sent', revision=%d, expires_on=%s, total=%s, currency=%s, customer_id=%d, email=%s, customer_name=%s, company=%s, order_id=0, access_secret=%s, updated_at=%s WHERE id=%d",
                $this->table('quotes'),
                $revision,
                $snapshot['valid_until'],
                $snapshot['totals']['total'],
                $snapshot['currency'],
                $customerId,
                $email,
                mb_substr($snapshot['customer']['name'], 0, 200),
                mb_substr($snapshot['customer']['company'], 0, 200),
                $recipientChanged ? bin2hex(random_bytes(16)) : $quote['access_secret'],
                $this->now(),
                $id
            ));
            if ($recipientChanged) {
                $this->insertEvent($id, $revision, 'link_reset', get_current_user_id(), __('Recipient changed', 'advanced-quotes-for-woocommerce'));
            }
            $this->insertEvent($id, $revision, 'sent', get_current_user_id(), $snapshot['customer']['email']);
            $this->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        return $this->revision($id, $revision);
    }

    /**
     * Load a sent revision and verify its integrity hash. Latest revision when $revision is null.
     */
    public function revision(int $quoteId, ?int $revision = null): ?array
    {
        global $wpdb;
        $row = $revision === null
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE quote_id=%d ORDER BY revision DESC LIMIT 1', $this->table('revisions'), $quoteId), ARRAY_A)
            : $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE quote_id=%d AND revision=%d', $this->table('revisions'), $quoteId, $revision), ARRAY_A);
        if (! $row) {
            return null;
        }
        if (! hash_equals($row['snapshot_hash'], hash('sha256', $row['snapshot']))) {
            throw new RuntimeException(__('This quote revision failed its integrity check. Restore it from a backup.', 'advanced-quotes-for-woocommerce'));
        }
        $row['id'] = (int) $row['id'];
        $row['revision'] = (int) $row['revision'];
        $row['data'] = json_decode($row['snapshot'], true, 64, JSON_THROW_ON_ERROR);

        return $row;
    }

    /**
     * @return list<array{id:int,revision:int,sent_at:string,sent_by:int,total:string,has_pdf:bool}>
     */
    public function revisions(int $quoteId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,revision,sent_at,sent_by,snapshot,pdf IS NOT NULL AS has_pdf FROM %i WHERE quote_id=%d ORDER BY revision DESC', $this->table('revisions'), $quoteId), ARRAY_A);

        return array_map(static function (array $row): array {
            $data = json_decode($row['snapshot'], true);

            return ['id' => (int) $row['id'], 'revision' => (int) $row['revision'], 'sent_at' => $row['sent_at'], 'sent_by' => (int) $row['sent_by'], 'total' => (string) ($data['totals']['total'] ?? ''), 'has_pdf' => (bool) $row['has_pdf']];
        }, $rows ?: []);
    }

    /**
     * PDF bytes for a revision. Rendered once, then kept unchanged and verified by hash.
     */
    public function pdf(array $revision, Renderer $renderer): string
    {
        global $wpdb;
        if ($revision['pdf'] !== null) {
            if (! hash_equals((string) $revision['pdf_hash'], hash('sha256', $revision['pdf']))) {
                throw new RuntimeException(__('The quote PDF failed its integrity check. Restore it from a backup.', 'advanced-quotes-for-woocommerce'));
            }

            return $revision['pdf'];
        }
        $bytes = $renderer->pdf($revision['data']);
        $this->query($wpdb->prepare('UPDATE %i SET pdf=%s, pdf_hash=%s WHERE id=%d AND pdf IS NULL', $this->table('revisions'), $bytes, hash('sha256', $bytes), $revision['id']));
        $stored = $this->revision((int) $revision['data']['quote_id'], $revision['revision']);

        return $this->pdf($stored, $renderer);
    }

    /**
     * Claim the acceptance of one sent revision. Returns a lock token, or null when another request owns it.
     */
    public function beginAcceptance(int $id, int $revision, string $today): ?string
    {
        global $wpdb;
        $token = bin2hex(random_bytes(16));
        $claimed = $this->query($wpdb->prepare(
            "UPDATE %i SET status='accepting', lock_token=%s, locked_at=%s, updated_at=%s WHERE id=%d AND status='sent' AND revision=%d AND (expires_on IS NULL OR expires_on >= %s)",
            $this->table('quotes'),
            $token,
            $this->now(),
            $this->now(),
            $id,
            $revision,
            $today
        ));

        return $claimed === 1 ? $token : null;
    }

    public function completeAcceptance(int $id, string $token, int $orderId, int $actorId, string $detail): void
    {
        global $wpdb;
        $done = $this->query($wpdb->prepare(
            "UPDATE %i SET status='accepted', order_id=%d, lock_token=NULL, locked_at=NULL, updated_at=%s WHERE id=%d AND status='accepting' AND lock_token=%s",
            $this->table('quotes'),
            $orderId,
            $this->now(),
            $id,
            $token
        ));
        if ($done !== 1) {
            throw new RuntimeException(__('The quote changed while it was being accepted.', 'advanced-quotes-for-woocommerce'));
        }
        $quote = $this->find($id);
        $this->insertEvent($id, $quote['revision'], 'accepted', $actorId, $detail);
    }

    /**
     * Record the new order under the acceptance lock, so an interrupted acceptance can be recovered.
     */
    public function attachOrder(int $id, string $token, int $orderId): void
    {
        global $wpdb;
        $this->query($wpdb->prepare("UPDATE %i SET order_id=%d WHERE id=%d AND status='accepting' AND lock_token=%s", $this->table('quotes'), $orderId, $id, $token));
    }

    /**
     * Finish an acceptance that stopped after its order was created.
     */
    public function recoverAcceptance(int $id, int $orderId): bool
    {
        global $wpdb;
        $done = (bool) $this->query($wpdb->prepare(
            "UPDATE %i SET status='accepted', lock_token=NULL, locked_at=NULL, updated_at=%s WHERE id=%d AND status='accepting' AND order_id=%d AND locked_at < %s",
            $this->table('quotes'),
            $this->now(),
            $id,
            $orderId,
            gmdate('Y-m-d H:i:s', time() - 120)
        ));
        if ($done) {
            $quote = $this->find($id);
            /* translators: %d: order ID */
            $this->insertEvent($id, $quote ? $quote['revision'] : 0, 'accepted', 0, sprintf(__('Order #%d recovered after an interrupted acceptance.', 'advanced-quotes-for-woocommerce'), $orderId));
        }

        return $done;
    }

    public function abortAcceptance(int $id, string $token): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE %i SET status='sent', order_id=0, lock_token=NULL, locked_at=NULL WHERE id=%d AND status='accepting' AND lock_token=%s", $this->table('quotes'), $id, $token));
    }

    /**
     * Return an acceptance that stopped midway to the sent state. Callers first recover it when its order exists.
     */
    public function releaseStaleAcceptance(int $id, int $olderThanSeconds = 120): bool
    {
        global $wpdb;

        return (bool) $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status='sent', order_id=0, lock_token=NULL, locked_at=NULL WHERE id=%d AND status='accepting' AND locked_at < %s",
            $this->table('quotes'),
            $id,
            gmdate('Y-m-d H:i:s', time() - $olderThanSeconds)
        ));
    }

    /**
     * Change status only from an expected state. Returns true when this call made the change.
     *
     * @param list<string> $from
     */
    public function transition(int $id, array $from, string $to, string $event, int $actorId = 0, string $detail = '', ?int $revision = null): bool
    {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($from), '%s'));
        $args = [$this->table('quotes'), $to, $this->now(), $id, ...$from];
        $sql = 'UPDATE %i SET status=%s, updated_at=%s WHERE id=%d AND status IN ('.$placeholders.')';
        if ($revision !== null) {
            $sql .= ' AND revision=%d';
            $args[] = $revision;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder list is generated from a caller status allowlist.
        $changed = $this->query($wpdb->prepare($sql, ...$args));
        if ($changed === 1) {
            $quote = $this->find($id);
            $this->insertEvent($id, $quote ? $quote['revision'] : 0, $event, $actorId, $detail);

            return true;
        }

        return false;
    }

    public function event(int $quoteId, string $kind, string $detail = '', ?int $actorId = null): void
    {
        $quote = $this->find($quoteId);
        $this->insertEvent($quoteId, $quote ? $quote['revision'] : 0, $kind, $actorId ?? get_current_user_id(), $detail);
    }

    private function insertEvent(int $quoteId, int $revision, string $kind, int $actorId, string $detail): void
    {
        global $wpdb;
        $ok = $wpdb->insert($this->table('events'), [
            'quote_id' => $quoteId,
            'revision' => $revision,
            'kind' => $kind,
            'actor_id' => $actorId,
            'detail' => mb_substr($detail, 0, 500),
            'happened_at' => $this->now(),
        ]);
        if (! $ok) {
            throw new RuntimeException(__('Quote history could not be saved.', 'advanced-quotes-for-woocommerce'));
        }
    }

    /**
     * @return list<array{kind:string,revision:int,actor_id:int,detail:string,happened_at:string}>
     */
    public function events(int $quoteId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare('SELECT kind,revision,actor_id,detail,happened_at FROM %i WHERE quote_id=%d ORDER BY id DESC LIMIT 100', $this->table('events'), $quoteId), ARRAY_A) ?: [];
    }

    /**
     * Admin list query. Expired is derived from sent quotes whose validity date has passed.
     */
    public function search(string $status, string $term, int $page, int $perPage, string $orderBy, string $order): array
    {
        global $wpdb;
        [$where, $args] = $this->where($status, $term);
        $orderBy = in_array($orderBy, ['number', 'status', 'customer_name', 'expires_on', 'updated_at', 'created_at'], true) ? $orderBy : 'id';
        $direction = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $sql = 'SELECT id,number,status,source,customer_id,email,customer_name,company,currency,total,revision,expires_on,order_id,created_at,updated_at FROM %i WHERE '.$where.' ORDER BY %i '.$direction.', id DESC LIMIT %d OFFSET %d';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where is built from fixed fragments; values are placeholders.
        $rows = $wpdb->get_results($wpdb->prepare($sql, $this->table('quotes'), ...array_merge($args, [$orderBy, $perPage, max(0, $page - 1) * $perPage])), ARRAY_A);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where is built from fixed fragments; values are placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE '.$where, $this->table('quotes'), ...$args));

        return [$rows ?: [], $total];
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    private function where(string $status, string $term): array
    {
        global $wpdb;
        $today = current_time('Y-m-d');
        $clauses = ["status <> 'deleted'"];
        $args = [];
        if ($status === 'expired') {
            $clauses[] = "status='sent' AND expires_on < %s";
            $args[] = $today;
        } elseif ($status === 'sent') {
            $clauses[] = "status='sent' AND (expires_on IS NULL OR expires_on >= %s)";
            $args[] = $today;
        } elseif ($status === 'accepted') {
            $clauses[] = "status IN ('accepted','accepting')";
        } elseif ($status !== '' && $status !== 'all') {
            $clauses[] = 'status=%s';
            $args[] = $status;
        }
        if ($term !== '') {
            $like = '%'.$wpdb->esc_like($term).'%';
            $clauses[] = '(number LIKE %s OR customer_name LIKE %s OR email LIKE %s OR company LIKE %s)';
            array_push($args, $like, $like, $like, $like);
        }

        return [implode(' AND ', $clauses), $args];
    }

    /**
     * @return array<string,int>
     */
    public function counts(): array
    {
        global $wpdb;
        $today = current_time('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT CASE WHEN status='sent' AND expires_on < %s THEN 'expired' WHEN status='accepting' THEN 'accepted' ELSE status END AS state, COUNT(*) AS n FROM %i GROUP BY state",
            $today,
            $this->table('quotes')
        ), ARRAY_A);
        $counts = ['all' => 0];
        foreach ($rows ?: [] as $row) {
            $counts[$row['state']] = (int) $row['n'];
            $counts['all'] += (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Quotes owned by a registered customer, newest first.
     */
    public function forCustomer(int $userId, int $limit = 50): array
    {
        global $wpdb;
        if ($userId <= 0) {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare("SELECT id,number,status,currency,total,revision,expires_on,order_id,created_at FROM %i WHERE customer_id=%d AND status NOT IN ('draft') ORDER BY id DESC LIMIT %d", $this->table('quotes'), $userId, $limit), ARRAY_A) ?: [];
    }

    /**
     * Quote records that contain an email address, for privacy export and erasure.
     */
    public function forEmail(string $email, int $page, int $perPage = 50): array
    {
        global $wpdb;
        $user = get_user_by('email', $email);

        return $wpdb->get_results($wpdb->prepare('SELECT id,number,status,order_id,draft,request FROM %i WHERE email=%s OR (customer_id>0 AND customer_id=%d) ORDER BY id LIMIT %d OFFSET %d', $this->table('quotes'), strtolower($email), $user ? (int) $user->ID : -1, $perPage, max(0, $page - 1) * $perPage), ARRAY_A) ?: [];
    }

    /**
     * Sent snapshots of a quote, for privacy export.
     *
     * @return list<string>
     */
    public function snapshots(int $quoteId): array
    {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare('SELECT snapshot FROM %i WHERE quote_id=%d ORDER BY revision', $this->table('revisions'), $quoteId)) ?: [];
    }

    /**
     * Delete a quote with its revisions and history. Used by privacy erasure for quotes without an order.
     */
    public function delete(int $id): void
    {
        global $wpdb;
        try {
            $this->query('START TRANSACTION');
            $this->query($wpdb->prepare('DELETE FROM %i WHERE quote_id=%d', $this->table('revisions'), $id));
            $this->query($wpdb->prepare('DELETE FROM %i WHERE quote_id=%d', $this->table('events'), $id));
            $this->query($wpdb->prepare('DELETE FROM %i WHERE id=%d', $this->table('quotes'), $id));
            $this->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public function nextNumber(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT next_number FROM %i WHERE id=1', $this->table('counter')));
    }
}
