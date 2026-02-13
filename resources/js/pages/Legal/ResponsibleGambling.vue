<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
</script>

<template>
  <AppLayout>
    <Head title="Responsible Gambling" />

    <div class="py-12">
      <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <Card>
          <CardHeader>
            <CardTitle class="text-3xl">Responsible Gambling</CardTitle>
            <p class="text-muted-foreground mt-2">
              Gambling should be entertaining, not harmful. Know the signs and get help if needed.
            </p>
          </CardHeader>
          <CardContent class="prose prose-sm dark:prose-invert max-w-none">
            <Alert class="mb-6 bg-red-50 dark:bg-red-950 border-red-200 dark:border-red-800">
              <AlertTitle class="text-red-900 dark:text-red-100 font-bold">Need Help Now?</AlertTitle>
              <AlertDescription class="text-red-800 dark:text-red-200">
                <p class="mb-2">If you or someone you know has a gambling problem, call the National Council on Problem Gambling 24/7 helpline:</p>
                <p class="text-2xl font-bold">1-800-522-4700</p>
                <p class="mt-2 text-sm">Or text "GAMBLER" to 1-800-522-4700</p>
              </AlertDescription>
            </Alert>

            <h2>Our Commitment</h2>
            <p>
              At PickSports, we are committed to promoting responsible gambling practices. While we provide sports prediction analytics and betting insights, we recognize that gambling can become problematic for some individuals. We encourage all users to gamble responsibly and within their means.
            </p>

            <h2>What is Problem Gambling?</h2>
            <p>
              Problem gambling is gambling behavior that causes disruptions in any major area of life: psychological, physical, social, or vocational. Problem gambling affects about 2-3% of the population and can lead to serious personal, family, and financial consequences.
            </p>

            <h2>Warning Signs of Problem Gambling</h2>
            <p>Consider whether you or someone you know shows these signs:</p>
            <ul>
              <li>Spending more time or money gambling than you can afford</li>
              <li>Having difficulty controlling, cutting back, or stopping gambling</li>
              <li>Chasing losses by gambling more to win back what you lost</li>
              <li>Gambling to escape problems or relieve stress, anxiety, or depression</li>
              <li>Lying to family members or others to hide the extent of gambling</li>
              <li>Borrowing money or selling possessions to gamble</li>
              <li>Risking or losing important relationships, jobs, or opportunities because of gambling</li>
              <li>Feeling restless or irritable when attempting to cut down on gambling</li>
              <li>Needing to gamble with increasing amounts of money to achieve excitement</li>
              <li>Making repeated unsuccessful efforts to control or stop gambling</li>
            </ul>

            <h2>Tips for Responsible Gambling</h2>

            <h3>Set Limits Before You Start</h3>
            <ul>
              <li>Set a budget: Decide how much money you can afford to lose before you start gambling</li>
              <li>Set time limits: Decide how much time you will spend gambling</li>
              <li>Never chase losses: Accept losses as part of the game and never try to win back what you've lost</li>
              <li>Don't gamble on credit: Only gamble with money you can afford to lose</li>
            </ul>

            <h3>Take Regular Breaks</h3>
            <ul>
              <li>Step away from gambling regularly to maintain perspective</li>
              <li>Don't gamble when you're upset, stressed, or depressed</li>
              <li>Balance gambling with other activities</li>
            </ul>

            <h3>Keep Perspective</h3>
            <ul>
              <li>Gambling should be entertainment, not a way to make money</li>
              <li>The house always has an advantage in the long run</li>
              <li>Winning streaks don't last forever</li>
              <li>Don't gamble to solve financial problems</li>
            </ul>

            <h2>Self-Assessment Tools</h2>
            <p>
              If you're concerned about your gambling, take a self-assessment quiz:
            </p>
            <ul>
              <li><a href="https://www.ncpgambling.org/help-treatment/self-assessment/" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 underline">National Council on Problem Gambling Self-Assessment</a></li>
              <li><a href="https://www.gamblingtherapy.org/en/gambling-test" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 underline">Gambling Therapy Assessment</a></li>
            </ul>

            <h2>Getting Help</h2>
            <p>
              If you think you have a gambling problem, help is available. These organizations provide free, confidential support:
            </p>

            <h3>National Council on Problem Gambling</h3>
            <ul>
              <li>24/7 Helpline: <strong>1-800-522-4700</strong></li>
              <li>Text: <strong>"GAMBLER"</strong> to 1-800-522-4700</li>
              <li>Live Chat: <a href="https://www.ncpgambling.org/chat" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 underline">ncpgambling.org/chat</a></li>
              <li>Website: <a href="https://www.ncpgambling.org" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 underline">ncpgambling.org</a></li>
            </ul>

            <h3>Gamblers Anonymous</h3>
            <ul>
              <li>Website: <a href="https://www.gamblersanonymous.org" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 underline">gamblersanonymous.org</a></li>
              <li>Find a meeting: <a href="https://www.gamblersanonymous.org/ga/locations" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 underline">Meeting Locator</a></li>
              <li>ISO Phone App available for iOS and Android</li>
            </ul>

            <h3>Gambling Therapy</h3>
            <ul>
              <li>Website: <a href="https://www.gamblingtherapy.org" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 underline">gamblingtherapy.org</a></li>
              <li>Free online support and counseling</li>
              <li>Available in multiple languages</li>
            </ul>

            <h3>SAMHSA National Helpline</h3>
            <ul>
              <li>Substance Abuse and Mental Health Services Administration</li>
              <li>24/7 Helpline: <strong>1-800-662-4357</strong></li>
              <li>Free, confidential treatment referral and information service</li>
            </ul>

            <h2>State-Specific Resources</h2>
            <p>Many states have their own problem gambling helplines and resources:</p>
            <ul>
              <li>Visit <a href="https://www.ncpgambling.org/state-affiliate-member-links/" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 underline">NCPG State Resources</a> to find your state's specific resources</li>
            </ul>

            <h2>For Family and Friends</h2>
            <p>
              If someone you care about has a gambling problem, these resources can help:
            </p>
            <ul>
              <li><strong>Gam-Anon:</strong> <a href="https://www.gam-anon.org" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 underline">gam-anon.org</a> - Support for family members and friends of problem gamblers</li>
              <li><strong>National Council on Problem Gambling Family Resources:</strong> <a href="https://www.ncpgambling.org/help-treatment/help-for-family-friends/" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 underline">Family Support</a></li>
            </ul>

            <h2>Self-Exclusion Programs</h2>
            <p>
              Most states and individual sportsbooks offer self-exclusion programs that allow you to voluntarily ban yourself from gambling establishments or online gambling sites. Contact your state's gaming commission or the customer service departments of sportsbooks for more information.
            </p>

            <h2>Our Tools to Help You Stay in Control</h2>
            <p>While we don't directly facilitate gambling, we encourage responsible use of our prediction service:</p>
            <ul>
              <li>Track your betting history using our bet tracking tool (coming soon) to monitor your spending</li>
              <li>Use our ROI calculator to understand the realistic expectations of sports betting profitability</li>
              <li>Remember that our predictions are for entertainment and informational purposes only</li>
              <li>Take breaks from checking predictions if you feel you're becoming too invested</li>
            </ul>

            <h2>Important Reminders</h2>
            <ul>
              <li>You must be 21 years or older to use this service</li>
              <li>Gambling should be fun, not a way to make money</li>
              <li>The majority of gamblers lose money in the long run</li>
              <li>Past performance does not guarantee future results</li>
              <li>If gambling is no longer fun, it's time to stop</li>
            </ul>
          </CardContent>
        </Card>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <Card class="bg-blue-50 dark:bg-blue-950 border-blue-200 dark:border-blue-800">
            <CardHeader>
              <CardTitle class="text-blue-900 dark:text-blue-100">Crisis Support</CardTitle>
            </CardHeader>
            <CardContent class="text-blue-800 dark:text-blue-200">
              <p class="font-semibold mb-2">24/7 Crisis Helplines:</p>
              <ul class="space-y-2">
                <li><strong>Problem Gambling:</strong> 1-800-522-4700</li>
                <li><strong>Suicide Prevention:</strong> 988</li>
                <li><strong>Mental Health Crisis:</strong> 1-800-662-4357</li>
              </ul>
            </CardContent>
          </Card>

          <Card class="bg-green-50 dark:bg-green-950 border-green-200 dark:border-green-800">
            <CardHeader>
              <CardTitle class="text-green-900 dark:text-green-100">Online Resources</CardTitle>
            </CardHeader>
            <CardContent class="text-green-800 dark:text-green-200">
              <ul class="space-y-2">
                <li><a href="https://www.ncpgambling.org" target="_blank" rel="noopener noreferrer" class="underline">ncpgambling.org</a></li>
                <li><a href="https://www.gamblersanonymous.org" target="_blank" rel="noopener noreferrer" class="underline">gamblersanonymous.org</a></li>
                <li><a href="https://www.gamblingtherapy.org" target="_blank" rel="noopener noreferrer" class="underline">gamblingtherapy.org</a></li>
                <li><a href="https://www.begambleaware.org" target="_blank" rel="noopener noreferrer" class="underline">begambleaware.org</a></li>
              </ul>
            </CardContent>
          </Card>
        </div>

        <Alert>
          <AlertTitle>Remember</AlertTitle>
          <AlertDescription>
            Seeking help is a sign of strength, not weakness. Problem gambling is treatable, and recovery is possible. If you're struggling, reach out today.
          </AlertDescription>
        </Alert>
      </div>
    </div>
  </AppLayout>
</template>
