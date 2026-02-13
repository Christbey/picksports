<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
</script>

<template>
  <AppLayout>
    <Head title="Terms of Service" />

    <div class="py-12">
      <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <Card>
          <CardHeader>
            <CardTitle class="text-3xl">Terms of Service</CardTitle>
            <p class="text-muted-foreground mt-2">Last Updated: February 10, 2026</p>
          </CardHeader>
          <CardContent class="prose prose-sm dark:prose-invert max-w-none">
            <h2>1. Acceptance of Terms</h2>
            <p>
              By accessing and using PickSports ("the Service"), you accept and agree to be bound by the terms and provision of this agreement. If you do not agree to these Terms of Service, please do not use the Service.
            </p>

            <h2>2. Description of Service</h2>
            <p>
              PickSports provides sports prediction analytics, statistical analysis, and betting information for entertainment purposes only. Our predictions are based on Elo ratings, efficiency metrics, and historical data analysis across multiple sports including NFL, NBA, MLB, College Basketball (Men's and Women's), WNBA, and College Football.
            </p>

            <h2>3. Age Requirement</h2>
            <p>
              You must be at least 21 years of age to use this Service. By using the Service, you represent and warrant that you are at least 21 years old. If you are under 21 years of age, you are not permitted to use or register for the Service.
            </p>

            <h2>4. Entertainment Purposes Only</h2>
            <p>
              <strong>IMPORTANT:</strong> All predictions, analytics, and recommendations provided by PickSports are for entertainment and informational purposes only. We do not encourage, promote, or facilitate gambling or betting activities. Users are solely responsible for their own betting decisions and activities.
            </p>

            <h2>5. No Guarantee of Accuracy</h2>
            <p>
              While we strive for accuracy in our predictions, past performance does not guarantee future results. Sports outcomes are inherently unpredictable, and our predictions should not be relied upon as investment advice or a guarantee of profitability. All predictions and analyses are provided "as is" without warranty of any kind.
            </p>

            <h2>6. User Accounts</h2>
            <p>
              You are responsible for maintaining the confidentiality of your account and password. You agree to accept responsibility for all activities that occur under your account. You must immediately notify us of any unauthorized use of your account.
            </p>

            <h2>7. Subscription and Payment Terms</h2>
            <p>
              Certain features of the Service require a paid subscription. By subscribing, you agree to pay all fees associated with your chosen subscription tier. Subscriptions automatically renew unless cancelled before the renewal date. Refunds are provided at our discretion and in accordance with our refund policy.
            </p>

            <h2>8. Prohibited Uses</h2>
            <p>You agree not to:</p>
            <ul>
              <li>Use the Service for any illegal purpose or to violate any laws</li>
              <li>Share your account credentials with others</li>
              <li>Attempt to scrape, copy, or redistribute our data without permission</li>
              <li>Reverse engineer or attempt to extract source code from the Service</li>
              <li>Use the Service to harass, abuse, or harm others</li>
              <li>Impersonate any person or entity</li>
              <li>Interfere with or disrupt the Service or servers</li>
            </ul>

            <h2>9. Intellectual Property</h2>
            <p>
              All content, features, and functionality of the Service, including but not limited to text, graphics, logos, predictions, algorithms, and software, are the exclusive property of PickSports and are protected by copyright, trademark, and other intellectual property laws.
            </p>

            <h2>10. Third-Party Links and Services</h2>
            <p>
              The Service may contain links to third-party websites or services (including sportsbooks and betting platforms) that are not owned or controlled by PickSports. We have no control over and assume no responsibility for the content, privacy policies, or practices of any third-party websites or services.
            </p>

            <h2>11. Disclaimer of Warranties</h2>
            <p>
              THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE" WITHOUT WARRANTIES OF ANY KIND, EITHER EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO IMPLIED WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, OR NON-INFRINGEMENT. WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, SECURE, OR ERROR-FREE.
            </p>

            <h2>12. Limitation of Liability</h2>
            <p>
              TO THE MAXIMUM EXTENT PERMITTED BY LAW, PICKSPORTS SHALL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, OR ANY LOSS OF PROFITS OR REVENUES, WHETHER INCURRED DIRECTLY OR INDIRECTLY, OR ANY LOSS OF DATA, USE, GOODWILL, OR OTHER INTANGIBLE LOSSES RESULTING FROM:
            </p>
            <ul>
              <li>Your use or inability to use the Service</li>
              <li>Any predictions, recommendations, or analyses provided by the Service</li>
              <li>Any betting or gambling losses incurred based on our predictions</li>
              <li>Unauthorized access to or alteration of your transmissions or data</li>
              <li>Any other matter relating to the Service</li>
            </ul>

            <h2>13. Indemnification</h2>
            <p>
              You agree to indemnify, defend, and hold harmless PickSports and its officers, directors, employees, and agents from and against any claims, liabilities, damages, losses, and expenses arising out of or in any way connected with your access to or use of the Service, your violation of these Terms, or your violation of any rights of another.
            </p>

            <h2>14. Geographic Restrictions</h2>
            <p>
              The Service is controlled and operated from the United States. We make no representations that the Service is appropriate or available for use in other locations. If you access the Service from outside the United States, you are responsible for compliance with local laws.
            </p>

            <h2>15. Changes to Terms</h2>
            <p>
              We reserve the right to modify or replace these Terms at any time. If a revision is material, we will provide at least 30 days' notice prior to any new terms taking effect. What constitutes a material change will be determined at our sole discretion. Continued use of the Service after changes become effective constitutes acceptance of the revised Terms.
            </p>

            <h2>16. Termination</h2>
            <p>
              We may terminate or suspend your account and access to the Service immediately, without prior notice or liability, for any reason whatsoever, including without limitation if you breach the Terms. Upon termination, your right to use the Service will immediately cease.
            </p>

            <h2>17. Governing Law</h2>
            <p>
              These Terms shall be governed by and construed in accordance with the laws of the United States, without regard to its conflict of law provisions. Any legal action or proceeding relating to your access to or use of the Service shall be instituted in a state or federal court in the United States.
            </p>

            <h2>18. Severability</h2>
            <p>
              If any provision of these Terms is held to be invalid or unenforceable, such provision shall be struck and the remaining provisions shall be enforced to the fullest extent under law.
            </p>

            <h2>19. Entire Agreement</h2>
            <p>
              These Terms constitute the entire agreement between you and PickSports regarding the use of the Service and supersede all prior and contemporaneous agreements and understandings.
            </p>

            <h2>20. Contact Information</h2>
            <p>
              If you have any questions about these Terms, please contact us at:
            </p>
            <p>
              <strong>Email:</strong> legal@picksports.com<br>
              <strong>Address:</strong> [Your Business Address]
            </p>

            <div class="mt-8 p-4 bg-orange-50 dark:bg-orange-950 border border-orange-200 dark:border-orange-800 rounded-lg">
              <p class="font-semibold text-orange-900 dark:text-orange-100">Important Gambling Disclaimer:</p>
              <p class="mt-2 text-orange-800 dark:text-orange-200">
                If you or someone you know has a gambling problem, help is available. Call the National Council on Problem Gambling Helpline at 1-800-522-4700 or visit
                <Link href="/responsible-gambling" class="underline">our Responsible Gambling page</Link> for resources.
              </p>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  </AppLayout>
</template>
