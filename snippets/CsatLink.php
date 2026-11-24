<?php
/**
 * Excerpt from Omnithread (module/omnithread/libraries/Core/Csat/Csat.php).
 * Shown for illustration only. The full source code is available at https://store.mikodes.com/p/omnithread/
 *
 * A satisfaction rating is asked for by a one-time link sent on the conversation's own channel. Only the SHA-256 of the
 * token is stored, the link expires, and an answer is accepted once: the UPDATE only matches an unanswered row, so two
 * parallel requests cannot both count. A low score can reopen the conversation.
 */

final class Csat
{
    // … constants, constructor, page(), summary() omitted

    /**
     * @return string|null the public URL sent to the customer
     */
    public function issue(int $threadId): ?string
    {
        $t = $this->db->selectOne('SELECT id, account_id, channel_type, assigned_staff_id, merged_into FROM ' . $this->db->table('ot_threads') . ' WHERE id = ?', [$threadId]);
        if ($t === null || !$this->wanted($t)) {
            return null;
        }
        $token = bin2hex(random_bytes(16));
        $now   = $this->clock->now();
        $id    = $this->db->insert($this->table, [
            'thread_id'    => $threadId,
            'account_id'   => (int) $t['account_id'],
            'channel_type' => Types::normalize((string) $t['channel_type']),
            'staff_id'     => (int) $t['assigned_staff_id'],
            'token_hash'   => hash('sha256', $token),
            'sent_at'      => $now->format('Y-m-d H:i:s'),
            'expires_at'   => $now->modify('+' . $this->expireDays() . ' days')->format('Y-m-d H:i:s'),
            'score'        => 0,
        ]);
        $url     = ($this->publicUrl)($token);
        $message = trim($this->settings->get('csat_message')) !== '' ? $this->settings->get('csat_message') : self::DEFAULT_MESSAGE;
        if (!$this->replier->systemReply($threadId, str_replace('{link}', $url, $message))) {
            $this->db->delete($this->table, ['id' => $id]);

            return null;
        }

        return $url;
    }

// …

    public function answer(string $token, int $score, string $comment): bool
    {
        $row = $this->find($token);
        if ($row === null || !in_array($score, self::SCORES, true) || $row['answered_at'] !== null || $this->expired($row)) {
            return false;
        }
        $now     = $this->clock->sql();
        $comment = mb_substr(trim($comment), 0, self::MAX_COMMENT);
        $saved   = $this->db->execute(
            'UPDATE ' . $this->table . ' SET score = ?, comment = ?, answered_at = ? WHERE id = ? AND answered_at IS NULL',
            [$score, $comment === '' ? null : $comment, $now, (int) $row['id']]
        );
        if ($saved !== 1) {
            return false; // answered a moment ago in another request
        }
        $threadId = (int) $row['thread_id'];
        $this->db->update($this->db->table('ot_threads'), ['csat_score' => $score, 'csat_at' => $now], ['id' => $threadId]);
        if ($this->threads !== null && $score < $this->settings->int('csat_reopen_below')) {
            $this->threads->reopen($threadId);
        }

        return true;
    }

}
