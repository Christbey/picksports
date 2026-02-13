# Subscription Platform Strategy & Roadmap

## Executive Summary

This document outlines the strategic plan to transform the current sports prediction platform into a viable subscription business. The approach focuses on building trust through proven accuracy, delivering genuine value beyond free alternatives, and creating a sustainable business model.

**Timeline:** 12-month roadmap
**Target:** 1,000 paying subscribers by Month 12
**Revenue Goal:** $15,000-$30,000 MRR by Month 12

---

## Phase 1: Foundation & Track Record (Months 1-3)

**Objective:** Build credibility and prove accuracy before charging users

### 1.1 Performance Tracking System
**Priority:** CRITICAL
**Status:** ✅ COMPLETED (Feb 10, 2026)

- [x] Create `prediction_performance` table to track all predictions
- [x] Build automated grading system (runs after games complete)
- [x] Calculate accuracy metrics:
  - Win/Loss record
  - Against the spread (ATS) percentage
  - Over/Under accuracy
  - ROI if $100 bet on every recommendation
- [x] Store daily/weekly/monthly snapshots

**Files created:**
- `app/Services/PerformanceStatistics.php` - Aggregates data across all sports
- `app/Actions/*/GradePredictions.php` - Already existed for all 7 sports
- Prediction tables already have grading fields (`actual_spread`, `actual_total`, `spread_error`, `winner_correct`, `graded_at`)

### 1.2 Public Dashboard
**Priority:** HIGH
**Status:** ✅ COMPLETED (Feb 10, 2026)

- [x] Build `/performance` page showing:
  - Overall record (e.g., "456-389 ATS, 54.0%")
  - By sport breakdown
  - Last 30 days performance
  - Season-to-date charts
  - Hypothetical bankroll growth (ROI calculator)
- [x] Add transparency section explaining methodology
- [x] Show both successful AND unsuccessful predictions

**Files created:**
- `resources/js/Pages/Performance.vue` - Full performance dashboard
- `app/Http/Controllers/PerformanceController.php` - Controller
- `routes/web.php` - Added `/performance` route (public, no auth)

**Bonus:** Added performance stat cards to homepage (`/`) showing:
- Overall accuracy
- Last 30 days performance
- ROI percentage
- Spread accuracy

### 1.3 Content Marketing Foundation
**Priority:** MEDIUM
**Status:** Not Started

- [ ] Create blog system or integrate with existing CMS
- [ ] Write 10-15 educational articles:
  - "How Elo Ratings Work"
  - "Understanding Efficiency Metrics"
  - "Reading Betting Lines"
  - "Bankroll Management 101"
- [ ] Create social media presence (Twitter/X primary)
- [ ] Daily posts with free predictions + track record

### 1.4 Legal & Compliance
**Priority:** HIGH
**Status:** Not Started

- [ ] Add Terms of Service
- [ ] Add Privacy Policy
- [ ] Add Responsible Gambling resources page
- [ ] Age verification (21+) on signup
- [ ] Clear disclaimers: "For entertainment purposes only"
- [ ] Consult with attorney on gambling-related services

**Files to create:**
- `resources/js/Pages/Legal/Terms.vue`
- `resources/js/Pages/Legal/Privacy.vue`
- `resources/js/Pages/Legal/ResponsibleGambling.vue`

---

## Phase 2: Enhanced Features & Free Tier (Months 4-6)

**Objective:** Add value-driving features and launch structured free tier

### 2.1 Bet Tracking Tool
**Priority:** HIGH
**Status:** Not Started

Users need to track their bets to see if following predictions is profitable.

- [ ] Create `user_bets` table
- [ ] Build bet logging interface
- [ ] Auto-populate from predictions (one-click log)
- [ ] Show user's personal W/L record
- [ ] Calculate user's ROI
- [ ] Compare user performance vs. following all picks

**Features:**
- Log bet: amount, odds, result
- Track units won/lost
- Filter by sport, date range
- Export to CSV

**Files to create:**
- `app/Models/UserBet.php`
- `resources/js/Pages/MyBets.vue`
- `app/Http/Controllers/BetTrackerController.php`

### 2.2 Alerts System
**Priority:** HIGH
**Status:** ✅ COMPLETED (Feb 10, 2026)

Push high-value betting opportunities to users in real-time.

- [x] Email notifications for +EV bets
- [x] SMS notifications (Twilio integration)
- [x] Web push notifications
- [x] User preferences (which sports, minimum edge, time windows)
- [x] Digest options (daily summary vs. real-time)
- [x] Admin permission system (trigger-alerts, view-alert-stats)

**Files created:**
- `app/Models/UserAlertPreference.php` - User notification preferences model
- `app/Http/Controllers/Settings/AlertPreferenceController.php` - Manage preferences
- `app/Services/AlertService.php` - Alert detection and sending logic
- `resources/js/Pages/settings/AlertPreferences.vue` - User preference UI
- Tests: `tests/Feature/Settings/AlertPreferenceControllerTest.php`, `tests/Feature/UserAlertPreferenceTest.php`

### 2.2.1 Notification Template Management (Admin)
**Priority:** HIGH
**Status:** In Progress (Feb 10, 2026)

Admin interface to configure multi-channel notification templates.

- [ ] Create `notification_templates` table
- [ ] Build `NotificationTemplate` model with multi-channel support
- [ ] Create admin CRUD interface for templates
- [ ] Template variable system (e.g., `{user_name}`, `{game}`, `{edge}`)
- [ ] Preview functionality for each channel
- [ ] Integrate templates with AlertService

**Template Channels:**
- Email (subject + HTML/Markdown body)
- SMS (plain text, 160 chars)
- Push notifications (title + body)

**Files to create:**
- `app/Models/NotificationTemplate.php`
- `database/migrations/xxxx_create_notification_templates_table.php`
- `app/Http/Controllers/Admin/NotificationTemplateController.php`
- `resources/js/Pages/admin/NotificationTemplates/Index.vue`
- `resources/js/Pages/admin/NotificationTemplates/Edit.vue`

### 2.3 Line Movement Tracking
**Priority:** MEDIUM
**Status:** Not Started

Show how lines have moved since predictions were made.

- [ ] Store historical odds snapshots (hourly)
- [ ] Display line movement charts on game pages
- [ ] Highlight "steam moves" (sharp money indicators)
- [ ] Show closing line value (CLV) for graded predictions

**Database:**
- `odds_history` table with timestamps

### 2.4 Free Tier Definition
**Priority:** CRITICAL
**Status:** Not Started

Define what free users get to attract sign-ups.

**Free Tier Access:**
- Today's games only (no historical)
- Basic predictions (spread, total, win prob)
- 1-2 sports of choice
- Performance dashboard (read-only)
- Educational content
- Limited betting recommendations (3 per week)

**Restrictions:**
- No historical data
- No advanced metrics
- No alerts
- No bet tracking
- Watermarked exports

---

## Phase 3: Paid Tiers Launch (Months 7-9)

**Objective:** Launch subscription offerings with proven track record

### 3.1 Subscription Tiers
**Priority:** CRITICAL
**Status:** Not Started

#### **BASIC - $14.99/month**
- All sports, all predictions
- Full betting recommendations
- Historical data (current season)
- Email alerts (daily digest)
- Performance tracking
- Ad-free experience

#### **PRO - $39.99/month**
- Everything in Basic
- Advanced metrics visible
- Real-time SMS/push alerts
- Historical data (all seasons)
- Bet tracking tool
- Line movement charts
- API access (100 calls/day)
- Discord community access

#### **VIP - $99.99/month** (Limited to 100 users)
- Everything in Pro
- Priority support
- Custom analysis requests (2/month)
- Early access to new features
- API access (unlimited)
- Video breakdowns (weekly)
- Direct access to analyst

### 3.2 Payment Integration
**Priority:** CRITICAL
**Status:** Not Started

- [ ] Integrate Stripe subscription billing
- [ ] Handle plan upgrades/downgrades
- [ ] Trial period (7 days for Basic, 14 days for Pro)
- [ ] Cancellation flow with exit survey
- [ ] Failed payment handling
- [ ] Invoice generation

**Files to modify:**
- Subscription management pages already exist
- Enhance with plan selection UI
- Add usage tracking

### 3.3 Onboarding Flow
**Priority:** HIGH
**Status:** Not Started

First impressions matter for conversion and retention.

- [ ] Welcome email series (5 emails over 2 weeks)
- [ ] In-app guided tour
- [ ] Quick start checklist:
  - Set sport preferences
  - Enable notifications
  - Log first bet
  - View methodology
  - Check performance stats
- [ ] Personalization survey

### 3.4 Social Proof & Testimonials
**Priority:** HIGH
**Status:** Not Started

- [ ] Collect testimonials from beta users
- [ ] Display success stories on landing page
- [ ] User-generated content (winning bet screenshots)
- [ ] Trust indicators:
  - "X predictions tracked"
  - "Y users helped"
  - "Z% accuracy this month"

---

## Phase 4: Growth & Optimization (Months 10-12)

**Objective:** Scale to 1,000 subscribers and optimize retention

### 4.1 Community Features
**Priority:** MEDIUM
**Status:** Not Started

- [ ] Discord/Slack community
- [ ] Leaderboards (who's the best predictor?)
- [ ] User comments on predictions
- [ ] Share functionality (social media cards)
- [ ] Weekly recap newsletters

### 4.2 Mobile Experience
**Priority:** HIGH
**Status:** Not Started

Critical for betting use case (users bet from their phones).

**Options:**
1. Progressive Web App (PWA) - Faster
2. Native apps (iOS/Android) - Better UX

**Minimum requirements:**
- Responsive design (already done?)
- Push notifications
- Add to home screen
- Offline support for today's picks
- Touch-optimized bet logging

### 4.3 Additional Revenue Streams
**Priority:** MEDIUM
**Status:** Not Started

Diversify beyond subscriptions.

#### Sportsbook Affiliates
- [ ] Partner with DraftKings, FanDuel, BetMGM
- [ ] Affiliate links in betting recommendations
- [ ] Track conversions
- [ ] Revenue share: typically $100-300 per user

#### API Access
- [ ] Productize API for developers
- [ ] Pricing: $99/month (1K calls/day) to $499/month (unlimited)
- [ ] Documentation site
- [ ] Rate limiting

#### White Label
- [ ] Package platform for media companies
- [ ] $5K-10K/month per client
- [ ] Custom branding, their domain

### 4.4 Advanced Analytics
**Priority:** LOW
**Status:** Not Started

For power users and differentiation.

- [ ] Prop bet predictions (player stats)
- [ ] Live win probability (in-game updates)
- [ ] Parlay optimizer
- [ ] Arbitrage opportunity detector
- [ ] Weather impact analysis (outdoor sports)
- [ ] Injury impact modeling

### 4.5 Retention Optimization
**Priority:** CRITICAL
**Status:** Ongoing

Target: <5% monthly churn

**Tactics:**
- [ ] Weekly engagement emails
- [ ] Win back campaigns for inactive users
- [ ] Churn prediction model (proactive outreach)
- [ ] Anniversary rewards
- [ ] Referral program (1 month free for referrer + referee)
- [ ] Annual plan discounts (15-20% off)

---

## Key Metrics to Track

### Acquisition Metrics
- Website visitors
- Free signups
- Free → Paid conversion rate (target: 5-10%)
- Customer Acquisition Cost (CAC)
- Organic vs. paid traffic

### Engagement Metrics
- Daily/Weekly/Monthly Active Users (DAU/WAU/MAU)
- Predictions viewed per session
- Time on site
- Bet tracking usage
- Email open rates (target: >30%)
- Alert click-through rates

### Revenue Metrics
- Monthly Recurring Revenue (MRR)
- Average Revenue Per User (ARPU)
- Lifetime Value (LTV)
- LTV:CAC ratio (target: 3:1)
- Churn rate (target: <5%/month)
- Expansion revenue (upgrades)

### Product Metrics
- Prediction accuracy by sport
- User bet success rate (vs. our predictions)
- Feature adoption rates
- Net Promoter Score (NPS) - target: >50

---

## Marketing Strategy

### Month 1-3: Organic Growth
- SEO-optimized content (blog posts)
- Twitter/X daily predictions + track record
- Reddit participation (r/sportsbook, sport-specific)
- Free tools/calculators (odds converter, parlay calculator)
- Guest posts on sports betting blogs

### Month 4-6: Community Building
- Launch Discord community
- Weekly video breakdowns (YouTube)
- Podcast appearances
- Partner with sports betting influencers
- Case studies (user success stories)

### Month 7-9: Paid Acquisition
- Google Ads (branded + comparison keywords)
- Facebook/Instagram Ads (lookalike audiences)
- YouTube pre-roll ads
- Podcast sponsorships
- Retargeting campaigns

### Month 10-12: Scaling
- Affiliate partnerships
- Strategic partnerships (media companies)
- PR push (TechCrunch, Sports Business Journal)
- Conference presence (sports betting expos)
- Referral program amplification

---

## Technical Infrastructure Needs

### Performance & Scalability
- [ ] Database optimization for real-time odds
- [ ] Caching strategy (Redis for hot data)
- [ ] CDN for static assets
- [ ] Background job processing (queue system already exists)
- [ ] API rate limiting

### Monitoring & Alerting
- [ ] Application monitoring (Sentry, Bugsnag)
- [ ] Uptime monitoring (Pingdom, UptimeRobot)
- [ ] Analytics (Google Analytics, Mixpanel)
- [ ] User behavior tracking (Hotjar, FullStory)
- [ ] A/B testing framework (Optimizely, VWO)

### Security
- [ ] Regular security audits
- [ ] PCI compliance (if storing payment info)
- [ ] DDoS protection (Cloudflare)
- [ ] Rate limiting on API endpoints
- [ ] Fraud detection (unusual betting patterns)

---

## Budget Estimates

### Year 1 Operating Costs

**Technology:**
- Hosting (AWS/Herd): $200-500/month
- Odds API (The Odds API): $200-500/month
- Email service (SendGrid/Postmark): $50-200/month
- SMS notifications (Twilio): $100-500/month
- Monitoring tools: $100/month
- **Total Tech: ~$650-1,800/month**

**Marketing:**
- Content creation: $1,000-2,000/month
- Paid ads: $2,000-5,000/month (ramping up)
- Tools (SEO, analytics): $200/month
- **Total Marketing: ~$3,200-7,200/month**

**Operations:**
- Legal/compliance: $2,000-5,000 (one-time setup)
- Accounting/bookkeeping: $200-500/month
- Customer support tools: $50-100/month
- **Total Ops: ~$250-600/month**

**Total Monthly: ~$4,100-9,600/month**
**Year 1 Total: ~$50,000-115,000**

### Revenue Projections

**Conservative Scenario:**
- Month 6: 50 paying users × $20 avg = $1,000 MRR
- Month 9: 200 paying users × $20 avg = $4,000 MRR
- Month 12: 500 paying users × $20 avg = $10,000 MRR

**Optimistic Scenario:**
- Month 6: 100 paying users × $25 avg = $2,500 MRR
- Month 9: 500 paying users × $25 avg = $12,500 MRR
- Month 12: 1,000 paying users × $25 avg = $25,000 MRR

**Breakeven:** Approximately Month 9-11 depending on scenario

---

## Risk Mitigation

### Risk: Poor Prediction Accuracy
**Mitigation:**
- Continuous model improvement
- Multiple models (ensemble approach)
- Conservative confidence thresholds
- Clear communication when uncertain
- Show all predictions (good and bad)

### Risk: Low Conversion (Free → Paid)
**Mitigation:**
- Strong free tier value
- Clear upgrade path
- Time-limited trials
- Value demonstration (ROI calculator)
- Exit intent offers

### Risk: High Churn
**Mitigation:**
- Engagement campaigns
- Feature education
- Community building
- Quick wins (early successful bets)
- Annual plans (lock-in)

### Risk: Legal/Regulatory
**Mitigation:**
- Legal counsel review
- Clear disclaimers
- Geo-restrictions where needed
- Responsible gambling resources
- Compliance monitoring

### Risk: Competition
**Mitigation:**
- Unique methodology transparency
- Superior UX
- Community focus
- Multi-sport advantage
- Continuous innovation

---

## Success Criteria

### Phase 1 Success (Month 3)
- ✅ 10,000+ free signups
- ✅ >52% accuracy ATS (profitable)
- ✅ 1,000+ daily active users
- ✅ 50+ blog posts published
- ✅ 5,000+ Twitter followers

### Phase 2 Success (Month 6)
- ✅ 25,000+ free signups
- ✅ 100+ paying beta users
- ✅ >55% accuracy ATS
- ✅ 3,000+ daily active users
- ✅ <10% monthly churn

### Phase 3 Success (Month 9)
- ✅ 500+ paying subscribers
- ✅ $10,000+ MRR
- ✅ >53% accuracy ATS (sustained)
- ✅ <5% monthly churn
- ✅ 4.5+ star average review

### Phase 4 Success (Month 12)
- ✅ 1,000+ paying subscribers
- ✅ $20,000+ MRR
- ✅ Profitable or near-profitable
- ✅ Product-market fit validated
- ✅ Scalable growth engine

---

## Next Steps

### Immediate Actions (This Week)
1. Set up performance tracking tables and grading system
2. Create public performance dashboard
3. Add legal pages (Terms, Privacy)
4. Start tracking metrics in spreadsheet
5. Write first 3 blog posts

### This Month
1. Launch free tier with restrictions
2. Build bet tracking MVP
3. Set up email notification system
4. Create content calendar (3 months)
5. Soft launch to friends/family for feedback

### Next Quarter
1. Achieve 52%+ accuracy benchmark
2. Grow to 10,000 free users
3. Build paid tier infrastructure
4. Create pricing pages
5. Beta test with 50 paying users

---

## Conclusion

This is a marathon, not a sprint. The sports betting prediction space is competitive, but there's room for a transparent, methodology-driven, user-focused platform.

**Critical success factors:**
1. **Prove accuracy first** - No one pays for bad predictions
2. **Build tools, not just numbers** - Help users actually profit
3. **Create community** - Retention through belonging
4. **Stay transparent** - Show the good and bad
5. **Iterate based on data** - Let metrics guide decisions

**The opportunity is real, but execution is everything.**

---

*Document Version: 1.0*
*Last Updated: February 10, 2026*
*Next Review: Monthly*
