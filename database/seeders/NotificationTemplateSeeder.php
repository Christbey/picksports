<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        NotificationTemplate::updateOrCreate(
            ['name' => 'Betting Value Alert'],
            [
                'description' => 'Default template for betting value opportunity alerts',
                'active' => true,
                'subject' => 'Value Alert: {prediction.edge_percentage} Edge on {prediction.game_description}',
                'email_body' => 'Hi {user.name},

We\'ve identified a high-value betting opportunity for {prediction.sport}:

**{prediction.game_description}**
Game Time: {prediction.game_date} at {prediction.game_time}

**Recommendation:** {prediction.recommended_pick}
**Expected Value:** {prediction.edge_percentage}
**Confidence:** {prediction.confidence}
**Odds:** {prediction.odds}

This alert meets your minimum edge requirement and was sent during your preferred notification window.

Manage your alert settings at {system.app_url}/settings/alert-preferences

Best of luck,
The {system.app_name} Team',
                'sms_body' => '{system.app_name}: {prediction.edge_percentage} edge on {prediction.game_description}. {prediction.recommended_pick}. Confidence: {prediction.confidence}',
                'push_title' => 'Value Alert: {prediction.edge_percentage}',
                'push_body' => '{prediction.game_description} - {prediction.recommended_pick}. Confidence: {prediction.confidence}',
            ]
        );
    }
}
