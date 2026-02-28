<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import AppLayout from '@/layouts/AppLayout.vue'
</script>

<template>
  <AppLayout>
    <Head title="Privacy Policy" />

    <div class="py-12">
      <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <Card>
          <CardHeader>
            <CardTitle class="text-3xl">Privacy Policy</CardTitle>
            <p class="text-muted-foreground mt-2">Last Updated: February 10, 2026</p>
          </CardHeader>
          <CardContent class="prose prose-sm dark:prose-invert max-w-none">
            <h2>1. Introduction</h2>
            <p>
              PickSports ("we," "our," or "us") is committed to protecting your privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our Service. Please read this privacy policy carefully. If you do not agree with the terms of this privacy policy, please do not access the Service.
            </p>

            <h2>2. Information We Collect</h2>

            <h3>2.1 Personal Information</h3>
            <p>We may collect personal information that you voluntarily provide to us when you:</p>
            <ul>
              <li>Register for an account</li>
              <li>Subscribe to a paid plan</li>
              <li>Contact us for support</li>
              <li>Participate in surveys or promotions</li>
            </ul>
            <p>This information may include:</p>
            <ul>
              <li>Name and email address</li>
              <li>Payment information (processed securely through Stripe)</li>
              <li>Date of birth (for age verification purposes)</li>
              <li>User preferences and settings</li>
            </ul>

            <h3>2.2 Automatically Collected Information</h3>
            <p>When you access the Service, we automatically collect certain information, including:</p>
            <ul>
              <li>IP address and device information</li>
              <li>Browser type and version</li>
              <li>Operating system</li>
              <li>Pages visited and time spent on pages</li>
              <li>Referring website addresses</li>
              <li>Clickstream data</li>
            </ul>

            <h3>2.3 Cookies and Tracking Technologies</h3>
            <p>
              We use cookies, web beacons, and similar tracking technologies to track activity on our Service and store certain information. You can instruct your browser to refuse all cookies or to indicate when a cookie is being sent. However, if you do not accept cookies, you may not be able to use some portions of our Service.
            </p>

            <h2>3. How We Use Your Information</h2>
            <p>We use the information we collect for various purposes, including to:</p>
            <ul>
              <li>Provide, operate, and maintain the Service</li>
              <li>Process your subscription payments and transactions</li>
              <li>Send you predictions, analytics, and updates</li>
              <li>Respond to your comments, questions, and customer service requests</li>
              <li>Send you technical notices, updates, security alerts, and administrative messages</li>
              <li>Monitor and analyze usage and trends to improve your experience</li>
              <li>Detect, prevent, and address technical issues and fraudulent activity</li>
              <li>Personalize your experience and deliver content relevant to your interests</li>
              <li>Comply with legal obligations</li>
            </ul>

            <h2>4. How We Share Your Information</h2>
            <p>We may share your information in the following situations:</p>

            <h3>4.1 Service Providers</h3>
            <p>
              We may share your information with third-party service providers who perform services on our behalf, such as payment processing (Stripe), email delivery, hosting services, customer service, and analytics. These service providers are authorized to use your personal information only as necessary to provide these services to us.
            </p>

            <h3>4.2 Business Transfers</h3>
            <p>
              If we are involved in a merger, acquisition, or sale of all or a portion of our assets, your information may be transferred as part of that transaction. We will notify you via email and/or a prominent notice on our Service of any change in ownership or uses of your personal information.
            </p>

            <h3>4.3 Legal Requirements</h3>
            <p>
              We may disclose your information if required to do so by law or in response to valid requests by public authorities (e.g., a court or government agency).
            </p>

            <h3>4.4 With Your Consent</h3>
            <p>
              We may disclose your personal information for any other purpose with your consent.
            </p>

            <h2>5. Data Retention</h2>
            <p>
              We will retain your personal information only for as long as is necessary for the purposes set out in this Privacy Policy. We will retain and use your information to the extent necessary to comply with our legal obligations, resolve disputes, and enforce our policies.
            </p>

            <h2>6. Data Security</h2>
            <p>
              We implement appropriate technical and organizational security measures designed to protect your personal information against accidental or unlawful destruction, loss, alteration, unauthorized disclosure, or access. However, please note that no method of transmission over the Internet or method of electronic storage is 100% secure.
            </p>
            <p>Security measures we employ include:</p>
            <ul>
              <li>SSL/TLS encryption for data in transit</li>
              <li>Secure password storage using industry-standard hashing</li>
              <li>Regular security audits and vulnerability assessments</li>
              <li>Access controls and authentication requirements</li>
              <li>Secure payment processing through PCI-compliant providers (Stripe)</li>
            </ul>

            <h2>7. Your Privacy Rights</h2>
            <p>Depending on your location, you may have certain rights regarding your personal information:</p>

            <h3>7.1 Access and Portability</h3>
            <p>
              You have the right to request access to the personal information we hold about you and to receive a copy of that information in a structured, commonly used format.
            </p>

            <h3>7.2 Correction</h3>
            <p>
              You have the right to request that we correct any inaccurate or incomplete personal information we hold about you.
            </p>

            <h3>7.3 Deletion</h3>
            <p>
              You have the right to request that we delete your personal information, subject to certain exceptions (e.g., legal obligations, dispute resolution).
            </p>

            <h3>7.4 Opt-Out of Marketing</h3>
            <p>
              You may opt out of receiving marketing emails from us by following the unsubscribe link in our emails or by contacting us directly. Note that you may still receive transactional emails related to your account or subscription.
            </p>

            <h3>7.5 Do Not Track</h3>
            <p>
              Most web browsers and some mobile operating systems include a Do-Not-Track ("DNT") feature or setting. Our Service does not currently respond to DNT signals.
            </p>

            <h2>8. Children's Privacy</h2>
            <p>
              Our Service is not intended for individuals under the age of 21. We do not knowingly collect personal information from anyone under 21 years of age. If you are a parent or guardian and believe your child has provided us with personal information, please contact us immediately.
            </p>

            <h2>9. Third-Party Links</h2>
            <p>
              The Service may contain links to third-party websites or services that are not owned or controlled by PickSports. We are not responsible for the privacy practices of these third-party sites. We encourage you to review the privacy policies of any third-party sites you visit.
            </p>

            <h2>10. International Data Transfers</h2>
            <p>
              Your information may be transferred to and maintained on computers located outside of your state, province, country, or other governmental jurisdiction where the data protection laws may differ from those in your jurisdiction. By using the Service, you consent to the transfer of your information to the United States.
            </p>

            <h2>11. California Privacy Rights (CCPA)</h2>
            <p>
              If you are a California resident, you have specific rights regarding your personal information under the California Consumer Privacy Act (CCPA):
            </p>
            <ul>
              <li>Right to know what personal information is collected, used, shared, or sold</li>
              <li>Right to delete personal information held by businesses</li>
              <li>Right to opt-out of the sale of personal information (we do not sell personal information)</li>
              <li>Right to non-discrimination for exercising your CCPA rights</li>
            </ul>
            <p>
              To exercise these rights, please contact us at privacy@picksports.com. We will verify your identity before processing your request.
            </p>

            <h2>12. European Privacy Rights (GDPR)</h2>
            <p>
              If you are located in the European Economic Area (EEA), you have certain data protection rights under the General Data Protection Regulation (GDPR). We aim to take reasonable steps to allow you to correct, amend, delete, or limit the use of your personal information.
            </p>

            <h2>13. Changes to This Privacy Policy</h2>
            <p>
              We may update our Privacy Policy from time to time. We will notify you of any changes by posting the new Privacy Policy on this page and updating the "Last Updated" date. You are advised to review this Privacy Policy periodically for any changes. Changes to this Privacy Policy are effective when they are posted on this page.
            </p>

            <h2>14. Contact Us</h2>
            <p>
              If you have any questions about this Privacy Policy, please contact us:
            </p>
            <p>
              <strong>Email:</strong> privacy@picksports.com<br>
              <strong>Address:</strong> [Your Business Address]
            </p>

            <div class="mt-8 p-4 bg-blue-50 dark:bg-blue-950 border border-blue-200 dark:border-blue-800 rounded-lg">
              <p class="font-semibold text-blue-900 dark:text-blue-100">Data Protection Officer:</p>
              <p class="mt-2 text-blue-800 dark:text-blue-200">
                For specific privacy concerns or to exercise your data protection rights, you may contact our Data Protection Officer at dpo@picksports.com.
              </p>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  </AppLayout>
</template>
