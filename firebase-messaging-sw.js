importScripts('https://www.gstatic.com/firebasejs/8.10.1/firebase-app.js');
importScripts('https://www.gstatic.com/firebasejs/8.10.1/firebase-messaging.js');

// Updated Firebase config from Firebase Console
const firebaseConfig = {
  apiKey: "AIzaSyDIqgFzpjKQw2YXS7GjY6M2XceikiKr4BI",
  authDomain: "zipzapzoi-classifieds.firebaseapp.com",
  projectId: "zipzapzoi-classifieds",
  storageBucket: "zipzapzoi-classifieds.firebasestorage.app",
  messagingSenderId: "383047120473",
  appId: "1:383047120473:web:86e0fa7096063be18a2348",
  measurementId: "G-S8WVLZL53C"
};

firebase.initializeApp(firebaseConfig);
const messaging = firebase.messaging();

messaging.onBackgroundMessage((payload) => {
  console.log('[firebase-messaging-sw.js] Received background message ', payload);
  const notificationTitle = payload.notification.title;
  const notificationOptions = {
    body: payload.notification.body,
    icon: '/images/watermark.png'
  };

  self.registration.showNotification(notificationTitle, notificationOptions);
});
