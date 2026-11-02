import os
from playwright.sync_api import sync_playwright

base = os.environ['FIX02_BASE']
username = os.environ['FIX02_USERNAME']
password = os.environ['FIX02_PASSWORD']
thread1 = os.environ['FIX02_THREAD1']
thread2 = os.environ['FIX02_THREAD2']


def main():
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page()

        # Navigate to the app and log in.
        page.goto(base + '/')
        page.wait_for_selector('#login-form', timeout=10000)
        page.fill('input[name="username"]', username)
        page.fill('input[name="password"]', password)
        page.click('#login-form button[type="submit"]')
        page.wait_for_selector('#message-form', timeout=10000)

        # Navigate to thread 1.
        page.goto(f'{base}/chat?thread={thread1}')
        page.wait_for_selector('#message-form', timeout=10000)

        # Send a slow streaming message from the browser.
        page.fill('#prompt', 'AVATAR_STREAM')
        page.click('#send-button')

        # Wait for the live stream to appear and start producing watermelon chunks.
        page.wait_for_selector('#live-message', timeout=10000)
        page.wait_for_function(
            '() => document.querySelector("#live-body")?.textContent.includes("watermelon")',
            timeout=10000,
        )

        # Abort only /fragments/thread fetches while the stream runs.
        page.route('**/fragments/thread*', lambda route: route.abort())

        # Click the thread 2 link to trigger openThread; its fetch will abort.
        page.click(f'[data-thread="{thread2}"]')

        # A failed fetch must show an error toast.
        page.wait_for_selector('#toast.visible', timeout=5000)

        # The old view must remain visible: live stream still present.
        assert page.is_visible('#live-message'), 'Old streaming view must remain visible after failed navigation'

        # Remove the abort interception so subsequent fetches work.
        page.unroute('**/fragments/thread*')

        # The stream must complete and the final persisted response must arrive
        # without a page refresh: live message removed, assistant message present.
        page.wait_for_function('() => !document.querySelector("#live-message")', timeout=15000)
        page.wait_for_function(
            '() => !!document.querySelector(".message.assistant[data-id]")',
            timeout=5000,
        )

        browser.close()


main()
