export const storageKey = 'march-madness-bracket-builder-v3';
export const bracketLockAtIso = '2026-03-19T11:00:00-05:00';
export const regionNames = ['East', 'West', 'South', 'Midwest'];
export const roundOf64SeedPairings: Array<[number, number]> = [
    [1, 16],
    [8, 9],
    [5, 12],
    [4, 13],
    [6, 11],
    [3, 14],
    [7, 10],
    [2, 15],
];
export const roundLabels: Record<string, string> = {
    first_four: 'First Four',
    round_of_64: 'Round of 64',
    round_of_32: 'Round of 32',
    sweet_16: 'Sweet 16',
    elite_8: 'Elite 8',
    final_four: 'Final Four',
    national_championship: 'National Championship',
};
export const roundPoints: Record<string, number> = {
    first_four: 0,
    round_of_64: 10,
    round_of_32: 20,
    sweet_16: 40,
    elite_8: 80,
    final_four: 160,
    national_championship: 320,
};
export const accentByRegion: Record<string, string> = {
    East: 'from-sky-500/8 via-cyan-500/4 to-transparent dark:from-sky-500/18 dark:via-cyan-500/8',
    West: 'from-amber-500/8 via-orange-500/4 to-transparent dark:from-amber-500/18 dark:via-orange-500/8',
    South: 'from-emerald-500/8 via-teal-500/4 to-transparent dark:from-emerald-500/18 dark:via-teal-500/8',
    Midwest:
        'from-rose-500/8 via-fuchsia-500/4 to-transparent dark:from-rose-500/18 dark:via-fuchsia-500/8',
    Unassigned:
        'from-foreground/[0.03] via-transparent to-transparent dark:from-foreground/[0.06]',
};
export const bracketFallbackBySeason: Record<
    number,
    Record<string, Record<number, { name: string; abbreviation: string }>>
> = {
    2026: {
        West: {
            1: { name: 'Arizona Wildcats', abbreviation: 'ARIZ' },
            2: { name: 'Purdue Boilermakers', abbreviation: 'PUR' },
            3: { name: 'Gonzaga Bulldogs', abbreviation: 'GONZ' },
            4: { name: 'Arkansas Razorbacks', abbreviation: 'ARK' },
            5: { name: 'Wisconsin Badgers', abbreviation: 'WIS' },
            6: { name: 'BYU Cougars', abbreviation: 'BYU' },
            7: { name: 'Miami Hurricanes', abbreviation: 'MIA' },
            8: { name: 'Villanova Wildcats', abbreviation: 'VILL' },
            9: { name: 'Utah State Aggies', abbreviation: 'USU' },
            10: { name: 'Missouri Tigers', abbreviation: 'MIZ' },
            12: { name: 'High Point Panthers', abbreviation: 'HPU' },
            13: { name: "Hawai'i Rainbow Warriors", abbreviation: 'HAW' },
            14: { name: 'Kennesaw State Owls', abbreviation: 'KENN' },
            15: { name: 'Queens Royals', abbreviation: 'QUE' },
            16: { name: 'Long Island University Sharks', abbreviation: 'LIU' },
        },
        Midwest: {
            1: { name: 'Michigan Wolverines', abbreviation: 'MICH' },
            2: { name: 'Iowa State Cyclones', abbreviation: 'ISU' },
            3: { name: 'Virginia Cavaliers', abbreviation: 'UVA' },
            4: { name: 'Alabama Crimson Tide', abbreviation: 'ALA' },
            5: { name: 'Texas Tech Red Raiders', abbreviation: 'TTU' },
            6: { name: 'Tennessee Volunteers', abbreviation: 'TENN' },
            7: { name: 'Kentucky Wildcats', abbreviation: 'UK' },
            8: { name: 'Georgia Bulldogs', abbreviation: 'UGA' },
            9: { name: 'Saint Louis Billikens', abbreviation: 'SLU' },
            10: { name: 'Santa Clara Broncos', abbreviation: 'SCU' },
            12: { name: 'Akron Zips', abbreviation: 'AKR' },
            13: { name: 'Hofstra Pride', abbreviation: 'HOF' },
            14: { name: 'Wright State Raiders', abbreviation: 'WRST' },
            15: { name: 'Tennessee State Tigers', abbreviation: 'TNST' },
        },
        South: {
            1: { name: 'Florida Gators', abbreviation: 'FLA' },
            2: { name: 'Houston Cougars', abbreviation: 'HOU' },
            3: { name: 'Illinois Fighting Illini', abbreviation: 'ILL' },
            4: { name: 'Nebraska Cornhuskers', abbreviation: 'NEB' },
            5: { name: 'Vanderbilt Commodores', abbreviation: 'VAN' },
            6: { name: 'North Carolina Tar Heels', abbreviation: 'UNC' },
            7: { name: "Saint Mary's Gaels", abbreviation: 'SMC' },
            8: { name: 'Clemson Tigers', abbreviation: 'CLEM' },
            9: { name: 'Iowa Hawkeyes', abbreviation: 'IOWA' },
            10: { name: 'Texas A&M Aggies', abbreviation: 'TA&M' },
            11: { name: 'VCU Rams', abbreviation: 'VCU' },
            12: { name: 'McNeese Cowboys', abbreviation: 'MCN' },
            13: { name: 'Troy Trojans', abbreviation: 'TROY' },
            14: { name: 'Pennsylvania Quakers', abbreviation: 'PENN' },
            15: { name: 'Idaho Vandals', abbreviation: 'IDHO' },
        },
    },
};
