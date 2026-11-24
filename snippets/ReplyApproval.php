<?php
/**
 * Excerpt from Omnithread (module/omnithread/libraries/Core/Compose/Composer.php).
 * Shown for illustration only. The full source code is available at https://store.mikodes.com/p/omnithread/
 *
 * Reply approval: a staff member without sending rights submits a draft, someone with "approve" sends it. A fingerprint of
 * everything that would be sent (recipients, subject, body, attachments) is stored at submission. If the draft changes
 * afterwards, or the approver looked at an older version, the approval is withdrawn and nothing is sent.
 */

final class Composer
{
    // … constructor, drafts, delivery omitted

    /** Any change to a submitted draft takes it back to its author: the approver must see what is sent. */
    private function withdrawApproval(int $draftId): void
    {
        $this->db->update($this->table, ['status' => '', 'approval_hash' => '', 'updated_at' => $this->clock->sql()], ['id' => $draftId, 'status' => 'pending']);
    }

    /**
     * A fingerprint of everything that would be sent: recipients, subject, body and
     * attachments. Stored when the draft is submitted; approve() refuses when it changed,
     * and the approval screen passes the fingerprint of the version it showed.
     */
    public function approvalHash(int $draftId): string
    {
        $d = $this->db->selectOne('SELECT to_json, cc_json, bcc_json, subject, body_html, account_id, thread_id FROM ' . $this->table . ' WHERE id = ?', [$draftId]);
        if ($d === null) {
            return '';
        }
        $files = $this->db->select('SELECT id, filename, size, storage_path FROM ' . $this->db->table('ot_attachments') . ' WHERE draft_id = ? ORDER BY id', [$draftId]);

        return hash('sha256', Json::enc(['draft' => $d, 'files' => $files]));
    }

// …

    /** Ask for approval of a draft. Editing it afterwards withdraws the request. */
    public function submitForApproval(Viewer $v, int $draftId): bool
    {
        $this->mine($v, $draftId);

        return $this->db->update($this->table, [
            'status' => 'pending', 'approval_note' => null, 'approval_hash' => $this->approvalHash($draftId), 'updated_at' => $this->clock->sql(),
        ], ['id' => $draftId, 'sending' => 0]) === 1;
    }

    /**
     * Send a submitted draft in its author's name. The approver needs "approve" and
     * access to the draft's mailbox; $seenHash is approvalHash() of the version the
     * approver looked at, so a draft changed in between is never sent.
     *
     * @return array{ok: bool, key: string, detail: string, thread_id: int, message_id: int, warning: string}
     */
    public function approve(Viewer $approver, int $draftId, string $seenHash = ''): array
    {
        $d = $this->pending($approver, $draftId);
        if ($d === null) {
            return self::failed('ot_err_draft_missing');
        }
        $now = $this->approvalHash($draftId);
        if (!hash_equals((string) $d['approval_hash'], $now) || ($seenHash !== '' && !hash_equals($seenHash, $now))) {
            $this->withdrawApproval($draftId);

            return self::failed('ot_err_draft_changed');
        }
        $this->db->update($this->table, ['approver_staff_id' => $approver->staffId], ['id' => $draftId]);

        return $this->deliver($d, (int) $d['staff_id']);
    }

}
