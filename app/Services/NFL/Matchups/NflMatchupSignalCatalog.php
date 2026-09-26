<?php

namespace App\Services\NFL\Matchups;

final class NflMatchupSignalCatalog
{
    public const VERSION = '2026-09-20.2';

    /** Rules are descriptive; overlapping ranks must never be added as independent evidence. */
    public function rules(): array
    {
        $rules = [];
        foreach ([1 => ['epa', 5], 5 => ['epa', 10], 11 => ['success_rate', 10], 19 => ['yards_per_play', 10], 51 => ['pass_epa', 5], 55 => ['pass_epa', 10], 101 => ['rush_epa', 5]] as $start => [$metric, $size]) {
            foreach ([['top', 'top'], ['top', 'bottom'], ['bottom', 'top'], ['bottom', 'bottom']] as $offset => [$offense, $defense]) {
                $rules[$start + $offset] = compact('metric', 'size', 'offense', 'defense');
            }
        }
        $rules[9] = ['metric' => 'epa', 'size' => null, 'offense' => 'above_average', 'defense' => 'below_average'];
        $rules[10] = ['metric' => 'epa', 'size' => null, 'offense' => 'below_average', 'defense' => 'above_average'];
        $rules[38] = ['metric' => 'points_per_game', 'size' => 10, 'offense' => 'top', 'defense' => 'bottom'];
        $rules[39] = ['metric' => 'points_per_game', 'size' => 10, 'offense' => 'bottom', 'defense' => 'top'];
        foreach ([26 => 'early_epa', 28 => 'late_epa', 32 => 'explosive_rate', 59 => 'pass_success_rate',
            61 => 'pass_explosive_rate', 105 => 'rush_success_rate', 127 => 'short_yardage_success_rate'] as $id => $metric) {
            $rules[$id] = ['metric' => $metric, 'size' => 10, 'offense' => 'top', 'defense' => 'bottom'];
            $rules[$id + 1] = ['metric' => $metric, 'size' => 10, 'offense' => 'bottom', 'defense' => 'top'];
        }
        foreach ([77 => 'first_down_pass_epa', 78 => 'third_down_pass_epa', 80 => 'red_zone_pass_epa',
            110 => 'rush_explosive_rate', 132 => 'first_down_rush_epa'] as $id => $metric) {
            $rules[$id] = ['metric' => $metric, 'size' => 10, 'offense' => 'top', 'defense' => 'bottom'];
        }
        $rules[79] = ['metric' => 'third_down_pass_epa', 'size' => 10, 'offense' => 'bottom', 'defense' => 'top'];
        ksort($rules);

        return $rules;
    }

    public function entries(): array
    {
        $rules = $this->rules();
        $entries = [];
        foreach (explode("\n", self::LABELS) as $line) {
            [$id, $label] = explode('. ', $line, 2);
            $id = (int) $id;
            $category = match (true) {
                $id <= 50 => 'overall', $id <= 100 => 'passing', $id <= 140 => 'rushing',
                $id <= 190 => 'offensive_line', $id <= 240 => 'quarterback_scheme',
                $id <= 280 => 'receivers_coverage', $id <= 320 => 'situational_records',
                default => 'travel_home_road',
            };
            $support = isset($rules[$id]) ? 'implemented' : ($id >= 281 ? 'situational_records' : 'unavailable');
            $reason = match ($support) {
                'implemented' => null,
                'situational_records' => $id === 353 ? 'Incomplete source definition; home-rest threshold is unspecified.' : 'Evaluated separately in situational records where the required inputs and definitions exist.',
                default => in_array($category, ['offensive_line', 'quarterback_scheme', 'receivers_coverage'], true)
                    ? 'Requires validated charting, personnel or injury inputs and an explicit threshold; no proxy is substituted.'
                    : 'Metric derivation or threshold is not yet validated; no signal is inferred from missing inputs.',
            };
            $entries[] = compact('id', 'label', 'category', 'support', 'reason');
        }

        return array_map(function (array $entry) use ($rules): array {
            $metric = $rules[$entry['id']]['metric'] ?? null;

            return [...$entry, 'definition' => $metric ? $this->definition($metric) : null,
                'required_inputs' => $metric ? ($metric === 'points_per_game' ? ['Final team scores', 'Complete league schedule'] : ['Mapped nflverse play-by-play', 'Eligible play values and situational fields', 'Complete league schedule']) : $this->requirements($entry['id'], $entry['category']),
                'prediction_effect' => 'none'];
        }, $entries);
    }

    public function definition(string $metric): string
    {
        return match ($metric) {
            'epa', 'pass_epa', 'rush_epa' => 'Play-weighted EPA on eligible pass/run plays; sacks count as passes. Higher offense and lower EPA allowed rank better.',
            'success_rate', 'pass_success_rate', 'rush_success_rate' => 'Share of eligible plays with EPA > 0, within the named play type. Defense is opponent success allowed; lower is better.',
            'explosive_rate', 'pass_explosive_rate', 'rush_explosive_rate' => 'Share of eligible plays gaining at least 20 passing yards or 10 rushing yards. Defense is explosive plays allowed; lower is better.',
            'early_epa' => 'Play-weighted EPA on first and second downs.',
            'late_epa' => 'Play-weighted EPA on third and fourth downs.',
            'first_down_pass_epa' => 'Passing EPA on first down, including sacks.',
            'third_down_pass_epa' => 'Passing EPA on third down, including sacks.',
            'red_zone_pass_epa' => 'Passing EPA when the ball is 0–20 yards from the opponent end zone, including sacks.',
            'first_down_rush_epa' => 'Rushing EPA on first down; provider-classified runs, excluding sacks.',
            'short_yardage_success_rate' => 'Share of eligible pass/run plays with 1–2 yards to go that gain at least the required yards. Not a charted blocking grade.',
            'yards_per_play' => 'Play-weighted yards gained on eligible pass/run plays, including sacks.',
            'points_per_game' => 'Team points scored/allowed per final game, including defensive and special-teams scoring.',
        }.' High/elite/strong means top 10 and low/weak/poor means bottom 10 where the supplied label has no numeric band; explicit top-5/top-10 bands take precedence. Thresholds describe this catalog, not validated betting edges.';
    }

    private function requirements(int $id, string $category): array
    {
        if ($id === 353) {
            return ['Complete definition of home-rest and its threshold'];
        }

        return match ($category) {
            'overall' => ['Validated metric derivation (drive, script, opponent adjustment or trend as named)', 'Explicit threshold and historical league sample'],
            'passing' => ['Charted passing split or receiver/tracking input named by this rule', 'Explicit threshold and verified historical coverage'],
            'rushing' => ['Charted run concept, contact, box or personnel input named by this rule', 'Explicit threshold and verified historical coverage'],
            'offensive_line' => ['Charted pass/run blocking and defensive-front data', 'Timestamped starter, injury and lineup history where named'],
            'quarterback_scheme' => ['QB-level charted coverage/pressure/scheme splits', 'Timestamped QB identity and availability', 'Explicit comparison threshold'],
            'receivers_coverage' => ['Verified receiver/coverage assignments or tracking data', 'Personnel and injury history where named', 'Explicit comparison threshold'],
            'situational_records' => ['Complete schedule and prior-game context for the named situation', 'Immutable pregame market snapshots for ATS/O-U'],
            default => ['Verified venue, travel, timezone, roof or historical weather context as named', 'Immutable pregame market snapshots for favorite/underdog/ATS rules'],
        };
    }

    private const LABELS = <<<'LABELS'
1. Top-5 offensive EPA vs top-5 defensive EPA
2. Top-5 offensive EPA vs bottom-5 defensive EPA
3. Bottom-5 offensive EPA vs top-5 defensive EPA
4. Bottom-5 offensive EPA vs bottom-5 defensive EPA
5. Top-10 offensive EPA vs top-10 defensive EPA
6. Top-10 offensive EPA vs bottom-10 defensive EPA
7. Bottom-10 offensive EPA vs top-10 defensive EPA
8. Bottom-10 offensive EPA vs bottom-10 defensive EPA
9. Above-average offensive EPA vs below-average defensive EPA
10. Below-average offensive EPA vs above-average defensive EPA
11. Top-10 offensive success rate vs top-10 defensive success rate
12. Top-10 offensive success rate vs bottom-10 defense
13. Bottom-10 offensive success rate vs top-10 defense
14. Bottom-10 offensive success rate vs bottom-10 defense
15. Top-10 points/drive offense vs top-10 points/drive defense
16. Top-10 points/drive offense vs bottom-10 defense
17. Bottom-10 points/drive offense vs top-10 defense
18. Bottom-10 points/drive offense vs bottom-10 defense
19. Top-10 yards/play offense vs top-10 yards/play defense
20. Top-10 yards/play offense vs bottom-10 defense
21. Bottom-10 yards/play offense vs top-10 defense
22. Bottom-10 yards/play offense vs bottom-10 defense
23. Top-10 first-down rate vs top-10 first-down defense
24. Top-10 first-down rate vs bottom-10 defense
25. Bottom-10 first-down rate vs top-10 defense
26. Top-10 early-down EPA vs bottom-10 early-down defense
27. Bottom-10 early-down EPA vs top-10 early-down defense
28. Top-10 late-down EPA vs bottom-10 late-down defense
29. Bottom-10 late-down EPA vs top-10 late-down defense
30. Top-10 neutral-script EPA vs bottom-10 defense
31. Bottom-10 neutral EPA vs top-10 defense
32. Top-10 explosive-play offense vs bottom-10 explosive defense
33. Bottom-10 explosive offense vs top-10 explosive defense
34. High consistency offense vs high-variance defense
35. High-variance offense vs high-variance defense
36. Top-10 drive success vs bottom-10 drive defense
37. Bottom-10 drive success vs top-10 drive defense
38. Top-10 scoring offense vs bottom-10 scoring defense
39. Bottom-10 scoring offense vs top-10 scoring defense
40. Offense improving 3 straight games vs declining defense
41. Offense declining 3 straight vs improving defense
42. Offense improving 5-game EPA vs season defense
43. Season offense vs defense improving last 5
44. Top offense by opponent-adjusted EPA vs weak defense
45. Weak opponent-adjusted offense vs elite defense
46. Offense outperforming expected points vs defense underperforming
47. Offense underperforming expected points vs defense outperforming
48. Top offensive DVOA-style efficiency vs bottom defensive efficiency
49. Large offense-defense efficiency differential
50. Extreme offense-defense efficiency differential
51. Top-5 passing EPA vs top-5 pass defense
52. Top-5 passing EPA vs bottom-5 pass defense
53. Bottom-5 passing EPA vs top-5 pass defense
54. Bottom-5 passing EPA vs bottom-5 pass defense
55. Top-10 passing EPA vs top-10 pass defense
56. Top-10 passing EPA vs bottom-10 pass defense
57. Bottom-10 passing EPA vs top-10 pass defense
58. Bottom-10 passing EPA vs bottom-10 pass defense
59. High pass success rate vs low defensive pass success
60. Low pass success vs elite defensive pass success
61. High explosive-pass rate vs high explosive-pass allowed
62. Low explosive passing vs elite explosive-pass prevention
63. High yards/attempt vs high YPA allowed
64. Low YPA vs low YPA allowed
65. High CPOE offense vs low completion defense
66. Low CPOE offense vs elite completion defense
67. High deep-pass EPA vs weak deep defense
68. High deep-pass EPA vs elite deep defense
69. High intermediate EPA vs weak intermediate defense
70. High short-pass EPA vs weak underneath defense
71. High play-action EPA vs defense weak against play action
72. Poor play-action offense vs defense strong against it
73. High screen EPA vs defense weak against screens
74. High RPO EPA vs defense weak against RPO
75. High shotgun passing EPA vs weak shotgun defense
76. Under-center passing success vs weak under-center defense
77. High first-down passing EPA vs weak first-down pass defense
78. High third-down passing EPA vs weak third-down pass defense
79. Poor third-down passing vs elite third-down pass defense
80. High red-zone passing EPA vs poor red-zone pass defense
81. High passing TD rate vs high TD rate allowed
82. Low INT offense vs low takeaway defense
83. High INT offense vs high takeaway defense
84. High air yards/attempt vs weak deep secondary
85. High YAC offense vs poor tackling secondary
86. Low YAC offense vs elite tackling secondary
87. High receiver separation vs man-heavy defense
88. Low receiver separation vs man-heavy defense
89. High contested-catch offense vs physical secondary
90. Pass-heavy offense vs weak pass defense
91. Pass-heavy offense vs elite pass defense
92. Low-PROE offense vs weak pass defense
93. High-PROE offense vs weak pass defense
94. High-PROE offense vs elite pass defense
95. Passing offense trending up vs pass defense trending down
96. Passing offense trending down vs defense trending up
97. Top road passing offense vs weak home pass defense
98. Top home passing offense vs weak road pass defense
99. Pass efficiency advantage ≥ defined threshold
100. Pass efficiency disadvantage ≥ defined threshold
101. Top-5 rush EPA vs top-5 run defense
102. Top-5 rush EPA vs bottom-5 run defense
103. Bottom-5 rush EPA vs top-5 run defense
104. Bottom-5 rush EPA vs bottom-5 run defense
105. Top-10 rush success vs bottom-10 run defense
106. Bottom-10 rush success vs top-10 run defense
107. High yards-before-contact vs weak defensive front
108. Low yards-before-contact vs elite front
109. High yards-after-contact vs poor tackling defense
110. Explosive rushing offense vs explosive runs allowed
111. Outside-zone offense vs weak outside-zone defense
112. Outside-zone offense vs elite outside-zone defense
113. Inside-zone offense vs weak inside-zone defense
114. Inside-zone offense vs elite inside-zone defense
115. Gap/power offense vs weak gap defense
116. Gap/power offense vs elite gap defense
117. Counter-heavy offense vs weak counter defense
118. Duo-heavy offense vs weak duo defense
119. High designed-QB-run offense vs poor QB-run defense
120. Mobile QB vs high scrambling EPA allowed
121. RB-heavy offense vs weak linebacker unit
122. Strong rushing offense vs light defensive boxes
123. Strong rushing offense vs heavy boxes
124. Weak rushing offense vs light boxes
125. High rush success vs low stuff rate defense
126. Low rush success vs high stuff rate defense
127. Top short-yardage offense vs weak short-yardage defense
128. Poor short-yardage offense vs elite short-yardage defense
129. Top goal-line rushing offense vs weak goal-line defense
130. Run-heavy offense vs bottom-10 run defense
131. Run-heavy offense vs top-10 run defense
132. High first-down rush EPA vs poor first-down run defense
133. High early-down rush rate vs weak run defense
134. Explosive RB vs defense allowing explosive RB runs
135. Strong OL rushing metrics vs weak DL
136. Weak OL rushing metrics vs strong DL
137. Rush offense trending up vs run defense trending down
138. Rush offense trending down vs run defense trending up
139. Large rush-efficiency matchup advantage
140. Extreme rush-efficiency matchup disadvantage
141. Top-5 pass-block OL vs top-5 pass rush
142. Top-5 OL vs bottom-5 pass rush
143. Bottom-5 OL vs top-5 pass rush
144. Bottom-5 OL vs bottom-5 pass rush
145. Top-10 pass-block win rate vs top-10 pass-rush win rate
146. Top-10 PBWR vs bottom-10 PRWR
147. Bottom-10 PBWR vs top-10 PRWR
148. Bottom-10 PBWR vs bottom-10 PRWR
149. Low pressure allowed vs high pressure defense
150. High pressure allowed vs high pressure defense
151. High sack allowed rate vs high sack defense
152. Low sack rate vs high-pressure defense
153. High pressure-to-sack OL/QB vs high pressure defense
154. OL missing one starter vs top-10 front
155. OL missing two starters vs top-10 front
156. OL missing 3+ starters vs top-10 front
157. LT out vs elite edge
158. RT out vs elite edge
159. Both tackles compromised vs strong edge duo
160. Center out vs elite interior DL
161. Guard out vs elite interior pressure
162. Multiple interior OL injuries vs interior-heavy rush
163. Rookie LT vs elite edge
164. Rookie RT vs elite edge
165. Backup tackle vs top-10 edge
166. Backup center vs high blitz rate
167. OL lineup changed from previous week
168. 2+ OL lineup changes
169. Same five OL starters 4+ consecutive games
170. High OL continuity vs unstable defensive front
171. Low OL continuity vs stable defensive front
172. Top run-block win rate vs poor run defense
173. Bottom run-block win rate vs elite run defense
174. Strong zone-blocking OL vs weak zone front
175. Strong gap-blocking OL vs weak gap front
176. High pressure offense weakness vs four-man pressure defense
177. High pressure offense weakness vs blitz-heavy defense
178. Strong pass protection vs blitz-heavy defense
179. Strong pass protection vs four-man rush
180. OL penalty-heavy vs disciplined DL
181. High holding rate vs elite edge
182. High false-start rate in road environment
183. OL disadvantage + road game
184. OL disadvantage + backup QB
185. OL disadvantage + immobile QB
186. OL advantage + mobile QB
187. OL advantage + elite passing QB
188. OL advantage + weak secondary
189. Large PBWR–PRWR differential
190. Extreme PBWR–PRWR differential
191. QB top-10 EPA vs top-10 defense
192. QB top-10 EPA vs bottom-10 defense
193. QB bottom-10 EPA vs top-10 defense
194. QB bottom-10 EPA vs bottom-10 defense
195. QB elite vs blitz vs blitz-heavy defense
196. QB poor vs blitz vs blitz-heavy defense
197. QB elite vs pressure vs high-pressure defense
198. QB poor vs pressure vs high-pressure defense
199. QB low pressure-to-sack rate vs high-pressure defense
200. QB high pressure-to-sack rate vs high-pressure defense
201. QB strong vs man vs man-heavy defense
202. QB weak vs man vs man-heavy defense
203. QB strong vs zone vs zone-heavy defense
204. QB weak vs zone vs zone-heavy defense
205. QB strong vs Cover 1 vs Cover-1-heavy defense
206. QB weak vs Cover 1 vs Cover-1-heavy defense
207. QB strong vs Cover 2 vs Cover-2-heavy defense
208. QB weak vs Cover 2 vs Cover-2-heavy defense
209. QB strong vs Cover 3 vs Cover-3-heavy defense
210. QB weak vs Cover 3 vs Cover-3-heavy defense
211. QB strong vs Cover 4 vs Cover-4-heavy defense
212. QB weak vs Cover 4 vs Cover-4-heavy defense
213. QB strong vs two-high safety looks
214. QB weak vs two-high safety looks
215. QB strong vs single-high
216. QB weak vs single-high
217. Mobile QB vs weak contain defense
218. Scrambling QB vs man coverage
219. Mobile QB vs defense allowing QB explosives
220. QB deep-ball strength vs weak deep defense
221. Poor deep passer vs defense forcing deep throws
222. Quick-release QB vs strong pass rush
223. Slow time-to-throw QB vs strong pass rush
224. High checkdown QB vs underneath weakness
225. High play-action QB vs play-action weakness
226. Strong RPO QB vs RPO weakness
227. QB turnover-prone vs takeaway-heavy defense
228. Ball-secure QB vs takeaway-dependent defense
229. QB first start vs top defense
230. Rookie QB vs blitz-heavy defense
231. Rookie QB vs disguise-heavy defense
232. Rookie QB on road vs top defense
233. Backup QB vs top-10 defense
234. Backup QB vs bottom-10 defense
235. QB returning from injury vs high-pressure defense
236. QB change during week vs strong defense
237. QB EPA trending up vs defense trending down
238. QB EPA trending down vs defense trending up
239. QB scheme-matchup advantage score
240. QB scheme-matchup disadvantage score
241. Elite WR1 vs weak CB1
242. Elite WR1 vs elite CB1
243. Elite WR2 vs weak CB2
244. Strong slot WR vs weak nickel CB
245. Strong TE vs weak TE coverage
246. Strong receiving RB vs poor LB coverage
247. Speed WR vs slow secondary
248. Physical WR vs undersized CB
249. Tall WR vs undersized secondary
250. Elite route runner vs man-heavy defense
251. High-separation WR vs man defense
252. Low-separation receivers vs man defense
253. Strong zone-beating receivers vs zone defense
254. Deep threat vs explosive-pass weakness
255. YAC-heavy receivers vs poor tackling defense
256. High target concentration vs CB1 injury
257. WR1 vs replacement CB
258. WR2 vs replacement CB
259. Slot WR vs replacement nickel
260. TE vs backup safety/LB
261. WR1 out vs elite secondary
262. WR1 out vs weak secondary
263. WR2 out vs elite secondary
264. TE1 out vs defense weak against TE
265. RB1 out vs weak run defense
266. Multiple WR injuries vs elite secondary
267. Multiple secondary injuries vs elite WR group
268. CB1 out vs high-target-share WR1
269. CB2 out vs deep WR2
270. Safety out vs deep-passing offense
271. Nickel CB out vs slot-heavy offense
272. LB injury vs TE-heavy offense
273. LB injury vs receiving RB
274. High 12-personnel offense vs weak LB/safety coverage
275. High 11-personnel offense vs weak nickel defense
276. High 21-personnel offense vs weak base defense
277. Motion-heavy offense vs poor motion defense
278. Bunch-heavy offense vs man-heavy defense
279. Receiver matchup advantage across 2+ positions
280. Defense has coverage advantage across 2+ positions
281. Team record after bye
282. Team ATS after bye
283. Team O/U after bye
284. Team record before bye
285. Team ATS before bye
286. Record when opponent is off bye
287. ATS when opponent is off bye
288. Record with 2+ rest-day advantage
289. ATS with 2+ rest-day advantage
290. Record with 4+ rest-day advantage
291. ATS with 4+ rest-day advantage
292. Record with rest disadvantage
293. ATS with rest disadvantage
294. Record on Thursday
295. ATS on Thursday
296. Record Sunday after Monday game
297. ATS Sunday after Monday game
298. Record after Thursday mini-bye
299. ATS after Thursday mini-bye
300. Record after overtime
301. ATS after overtime
302. Record after 70+ offensive snaps
303. Record after defense played 70+ snaps
304. Record after blowout win
305. ATS after blowout win
306. Record after blowout loss
307. ATS after blowout loss
308. Record after one-score win
309. ATS after one-score win
310. Record after one-score loss
311. ATS after one-score loss
312. Record after 2 straight wins
313. Record after 3+ straight wins
314. Record after 2 straight losses
315. Record after 3+ straight losses
316. ATS after 3+ wins
317. ATS after 3+ losses
318. Record following divisional game
319. Record before divisional game
320. Record between two divisional games
321. Team home record
322. Team home ATS
323. Team road record
324. Team road ATS
325. Record as home favorite
326. ATS as home favorite
327. Record as home underdog
328. ATS as home underdog
329. Record as road favorite
330. ATS as road favorite
331. Record as road underdog
332. ATS as road underdog
333. Record second consecutive road game
334. ATS second consecutive road game
335. Record third consecutive road game
336. Record after 1,000+ travel miles
337. Record after 2,000+ travel miles
338. Record crossing one time zone
339. Record crossing 2+ time zones
340. West→East record
341. West→East early kickoff record
342. East→West record
343. Record following international game
344. Record in international game
345. Record at altitude
346. Sea-level team at altitude
347. Dome team outdoors
348. Outdoor team indoors
349. Warm-weather team in cold game
350. Cold-weather team in hot game
351. Road team after home-heavy stretch
352. Home team after road-heavy stretch
353. Record with home-rest
LABELS;
}
