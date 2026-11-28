<?php

namespace App\Services\Auth;

use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\GroupJoinLink;
use App\Models\User;
use Illuminate\Database\Connection;
use RuntimeException;

/** Current token admission and its membership/acceptance writes share one owned transaction. */
final class GroupMembershipAdmission
{
    public function acceptInvitation(GroupInvitation $invitation, User $user): void
    {
        $this->accept($invitation, $user);
    }

    public function acceptJoinLink(GroupJoinLink $joinLink, User $user): void
    {
        $this->accept($joinLink, $user);
    }

    private function accept(GroupInvitation|GroupJoinLink $token, User $user): void
    {
        $connection = $token->getConnection();
        $name = $connection->getName();
        if (! is_string($name) || $name === '' || $user->getConnection() !== $connection
            || ! $token->exists || ! $user->exists || $token->isDirty($token->getKeyName()) || $user->isDirty($user->getKeyName())) {
            throw new RuntimeException('Group membership requires persisted identities on one connection.');
        }
        $tokenId = $token->getRawOriginal($token->getKeyName());
        $userId = $user->getRawOriginal($user->getKeyName());
        $tokenValue = $token->getRawOriginal('token');
        $query = function (string $class) use ($name, $connection) {
            $query = $class::on($name);
            if ($query->getQuery()->getConnection() !== $connection) {
                throw new RuntimeException('Group membership connection changed.');
            }

            return $query;
        };
        $connection->transaction(function () use ($token, $tokenId, $tokenValue, $userId, $query, $connection): void {
            $current = $query($token::class)->useWritePdo()->whereKey($tokenId)->where('token', $tokenValue)->lockForUpdate()->firstOrFail();
            abort_unless($current instanceof GroupInvitation ? $current->isPending() : $current->isActive(), 404);
            $group = $query(Group::class)->useWritePdo()->whereKey($current->group_id)->lockForUpdate()->firstOrFail();
            $currentUser = $query(User::class)->useWritePdo()->whereKey($userId)->lockForUpdate()->firstOrFail();
            if ($current instanceof GroupInvitation) {
                abort_unless(strcasecmp($currentUser->email, $current->email) === 0, 403);
            }
            $this->joinLockedGroup($group, $currentUser, $connection);
            if ($current instanceof GroupInvitation) {
                if ($current->forceFill(['accepted_by' => $currentUser->id, 'accepted_at' => now()])->save() !== true) {
                    throw new RuntimeException('Group invitation acceptance was not persisted.');
                }
            }
        });
    }

    private function joinLockedGroup(Group $group, User $user, Connection $connection): void
    {
        $members = $group->users();
        if ($members->getQuery()->getConnection() !== $connection) {
            throw new RuntimeException('Group membership relation changed connection.');
        }
        if ($members->where('users.id', $user->id)->lockForUpdate()->first() !== null) {
            return;
        }
        $group->users()->attach($user->id, [
            'role' => (int) $group->owner_id === (int) $user->id ? 'owner' : 'member',
            'joined_at' => now(),
        ]);
    }
}
