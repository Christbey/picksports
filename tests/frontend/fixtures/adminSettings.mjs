export function adminSettingsProps(scenario = 'normal') {
    const group = (id) => ({
        id,
        public_id: `group-${id}`,
        name: id === 1 ? 'Office Pool' : 'Friends Pool',
        season: 2026,
        members_count: id === 1 ? 2 : 0,
        brackets_count: id === 1 ? 3 : 0,
        members:
            id === 1
                ? [
                      {
                          id: 10,
                          name: 'Owner User',
                          email: 'owner@example.com',
                          role: 'owner',
                          joined_at: '2026-10-01T12:00:00Z',
                      },
                      {
                          id: 11,
                          name: 'Member User',
                          email: 'member@example.com',
                          role: 'member',
                          joined_at: null,
                      },
                  ]
                : [],
        join_link:
            id === 1
                ? {
                      token: 'fixture',
                      join_url: 'https://example.com/join/fixture',
                      expires_at: null,
                      created_at: '2026-10-01T12:00:00Z',
                  }
                : null,
        invitations:
            id === 1
                ? [
                      {
                          id: 21,
                          email: 'invite@example.com',
                          token: 'fixture-invite',
                          invite_url: 'https://example.com/invite/fixture',
                          accepted_at: null,
                          expires_at: null,
                          created_at: null,
                      },
                      {
                          id: 22,
                          email: 'accepted@example.com',
                          token: 'accepted',
                          invite_url: 'https://example.com/invite/accepted',
                          accepted_at: '2026-10-02T12:00:00Z',
                          expires_at: null,
                          created_at: null,
                      },
                  ]
                : [],
    });
    return {
        groups: scenario === 'empty' ? [] : [group(1), group(2)],
        foundingUsers: {
            enabled: scenario !== 'disabled',
            limit: 10,
            used: scenario === 'empty' ? 0 : 1,
            remaining: scenario === 'disabled' ? 0 : 9,
            role: 'founding_user',
            tier_slug: 'pro',
            tier_name: 'Pro',
            users:
                scenario === 'empty'
                    ? []
                    : [
                          {
                              id: 7,
                              name: 'Founding User',
                              email: 'founder@example.com',
                              created_at: '2026-10-01T12:00:00Z',
                          },
                      ],
        },
    };
}
