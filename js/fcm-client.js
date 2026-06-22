// FCM Web Client setup
function initializeFCM() {
  if (!firebase.apps.length) {
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
  }

  const messaging = firebase.messaging();

  messaging.requestPermission().then(() => {
    console.log("Notification permission granted.");
    return messaging.getToken({ vapidKey: 'BBK_dVKcz9bNoAzAO8nwd552RmP1YKxLqOQ6gx6aXGjCoL5tSBDBvrg6qEKn87PmR0dhNVD26xKM_bFo2j-Rjko' });
  }).then((currentToken) => {
    if (currentToken) {
      console.log("FCM Token:", currentToken);
      // Send the token to your server and update the UI if necessary
      sendTokenToServer(currentToken);
    } else {
      console.log('No registration token available. Request permission to generate one.');
    }
  }).catch((err) => {
    console.log('An error occurred while retrieving token. ', err);
  });

  messaging.onMessage((payload) => {
    console.log('Message received. ', payload);
    // Custom logic to handle foreground notifications (e.g. show toast)
    if(window.showToast) {
        window.showToast(payload.notification.title + ': ' + payload.notification.body, 'info');
    }
  });
}

function sendTokenToServer(token) {
    fetch('/api/auth.php?action=update_fcm', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ token: token })
    }).then(r => r.json()).then(data => {
        console.log('Token updated on server:', data);
    }).catch(console.error);
}

// Call this function when the user logs in or visits the dashboard
if (localStorage.getItem('zzz_user')) {
    initializeFCM();
}
