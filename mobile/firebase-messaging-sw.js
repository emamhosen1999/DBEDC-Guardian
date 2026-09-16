/* eslint-disable no-undef */
// FCM background-message service worker for the /mobile PWA.
//
// This is what lets an INSTALLED PWA (Add to Home Screen) show a system
// notification while the app is closed/backgrounded — including iOS 16.4+.
// Firebase web config values below are public (the same ones shipped in the web
// bundle); security is enforced by Firebase project rules, not by hiding them.
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: 'AIzaSyCwfSbrgNYCrhdmFIlU7pS7bVVT__lwOgo',
  authDomain: 'dbedc-erp.firebaseapp.com',
  projectId: 'dbedc-erp',
  storageBucket: 'dbedc-erp.firebasestorage.app',
  messagingSenderId: '551140686722',
  appId: '1:551140686722:web:d99b8829aad35e60232d9b',
});

const messaging = firebase.messaging();

// Background (app closed / not focused): render the system notification.
messaging.onBackgroundMessage((payload) => {
  const note = payload.notification || {};
  const data = payload.data || {};
  const title = note.title || data.title || 'DBEDC Guardian';
  self.registration.showNotification(title, {
    body: note.body || data.body || '',
    icon: '/mobile/icon-192.png',
    badge: '/mobile/icon-192.png',
    data,
    tag: data.tag || undefined,
  });
});

// Tapping the notification focuses an open PWA window or opens one.
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || '/mobile/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const client of list) {
        if (client.url.includes('/mobile/') && 'focus' in client) {
          client.postMessage({ type: 'notification-click', data: event.notification.data || {} });
          return client.focus();
        }
      }
      return self.clients.openWindow(target);
    })
  );
});
