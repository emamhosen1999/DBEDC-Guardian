import React from 'react';
import { Head } from '@inertiajs/react';

const APK_URL = '/apk/latest.apk'; // TODO: Update with real APK endpoint
const APK_VERSION = '1.0.0'; // TODO: Dynamically fetch version if needed
const APK_SIZE = '20 MB'; // TODO: Dynamically fetch size if needed

export default function InstallApp() {
    // Android detection
    const isAndroid = typeof navigator !== 'undefined' && /android/i.test(navigator.userAgent);

    return (
        <div className="min-h-screen flex flex-col items-center justify-center bg-gradient-to-br from-primary-50 via-secondary-50 to-background">
            <Head title="Install App" />
            <div className="bg-white shadow-lg rounded-xl p-8 max-w-md w-full text-center">
                <h1 className="text-2xl font-bold mb-4 text-gray-900">Install the DBEDC Mobile App</h1>
                <p className="mb-6 text-gray-700">To continue, you must install our official Android app.</p>
                <div className="mb-4">
                    <span className="inline-block bg-primary-100 text-primary-700 rounded px-3 py-1 text-sm font-semibold mr-2">Version: {APK_VERSION}</span>
                    <span className="inline-block bg-gray-100 text-gray-700 rounded px-3 py-1 text-sm font-semibold">Size: {APK_SIZE}</span>
                </div>
                {isAndroid ? (
                    <a
                        href={APK_URL}
                        className="inline-block bg-primary-600 hover:bg-primary-700 text-white font-bold py-3 px-6 rounded-lg text-lg transition mb-4"
                        download
                    >
                        Download APK
                    </a>
                ) : (
                    <div className="mb-4 text-red-600 font-semibold">This app is only available for Android devices.</div>
                )}
                <div className="text-left mt-6 text-sm text-gray-600">
                    <h2 className="font-semibold mb-2">How to install:</h2>
                    <ol className="list-decimal list-inside space-y-1">
                        <li>Tap <b>Download APK</b> above.</li>
                        <li>Open the downloaded file from your notifications or Downloads folder.</li>
                        <li>If prompted, allow installation from this source.</li>
                        <li>Complete the installation and open the app.</li>
                    </ol>
                </div>
            </div>
        </div>
    );
}
