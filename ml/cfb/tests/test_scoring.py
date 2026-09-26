import datetime as dt
import sys
import unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).parents[1]))
from scoring import ridge,predict,prior,fit,simulate,summary,tilt,rules,crps,compare,temper


def games(n=24):
    rows=[]
    for i in range(n):
        date=dt.datetime(2024,1,1,tzinfo=dt.timezone.utc)+dt.timedelta(days=i*7)
        drives=[]
        for j in range(20):
            drives.append({'offense_id':str(j%2+1),'offense_points':7 if j%3==0 else 0,'opponent_points':0,
                'period':1+j//5,'dropbacks':3,'pass_yards':21+(j%2)*9,'rushes':2,'rush_yards':8,'start_yards_to_endzone':75})
        for period,values in ((5,[0,3,7]),(6,[0,3,8]),(7,[0,2])):
            for points in values:
                drives.append({**drives[0],'period':period,'offense_points':points})
        rows.append({'game_id':i+1,'season':2024,'kickoff':date.isoformat(),'source_available_at':(date+dt.timedelta(days=1)).isoformat(),
            'home_id':'1','away_id':'2','home_score':28,'away_score':21,'drives':drives,'neutral':False,'fpi':{'home':5,'away':0}})
    return rows


class ScoringTests(unittest.TestCase):
    def test_ridge_recovers_direction_and_pools_sparse_effects(self):
        data=[({'offense:a':1,'defense:x':1},7,1)]*100+[({'offense:b':1,'defense:x':1},2,1)]*100
        model=ridge(data,10)
        self.assertGreater(predict(model,{'offense:a':1,'defense:x':1}),predict(model,{'offense:b':1,'defense:x':1}))
        self.assertLess(predict(ridge(data,1000),{'offense:a':1}),predict(model,{'offense:a':1}))

    def test_repaired_future_sources_never_enter_past_training(self):
        rows=games()
        rows[0]['source_available_at']='2026-09-25T00:00:00+00:00'
        result=prior(rows,'2024-06-01T00:00:00+00:00')
        self.assertNotIn(1,[r['game_id'] for r in result])
        self.assertIn(1,[r['game_id'] for r in prior(rows,'2024-06-01T00:00:00+00:00',True)])

    def test_distribution_is_deterministic_coherent_and_has_no_final_ties(self):
        model=fit(games(),'2024-08-01T00:00:00+00:00')
        row={**games()[0],'kickoff':'2024-09-01T00:00:00+00:00'}
        first=simulate(model,row,1000,51)
        self.assertEqual(first,simulate(model,row,1000,51))
        self.assertAlmostEqual(sum(r['probability'] for r in first),1)
        self.assertTrue(all(r['home']!=r['away'] for r in first))
        s=summary(first)
        self.assertAlmostEqual(s['total'],s['home']+s['away'])
        self.assertAlmostEqual(s['margin'],s['home']-s['away'])

    def test_missing_opponent_and_overtime_evidence_is_explicit(self):
        model=fit(games(),'2024-08-01T00:00:00+00:00')
        row={**games()[0],'kickoff':'2024-09-01T00:00:00+00:00','away_id':'unknown'}
        with self.assertRaisesRegex(ValueError,'history'):
            simulate(model,row,100)
        self.assertEqual(rules(2026,3),(True,True))
        self.assertEqual(rules(2020,3),(False,True))
        self.assertEqual(rules(2020,5),(True,True))

    def test_tilt_matches_target_and_calibration_preserves_mass(self):
        outcomes=[{'points':0,'weight':8},{'points':3,'weight':1},{'points':7,'weight':1}]
        d=tilt(outcomes,4)
        self.assertAlmostEqual(sum(o['points']*o['probability'] for o in d),4,places=6)
        self.assertAlmostEqual(sum(o['probability'] for o in temper(d,1.2)),1)
        self.assertEqual(compare({'games':[]},{'games':[]})['promotion_allowed'],False)

    def test_crps_zero_for_exact_observation(self):
        self.assertEqual(crps([{'home':21,'away':7,'probability':1}],14,'margin'),0)

if __name__=='__main__':
    unittest.main()
