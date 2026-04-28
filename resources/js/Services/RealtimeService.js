class RealtimeService {
    constructor() {
        this.eventSource = null;
        this.isConnected = false;
        this.listeners = new Map();
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.reconnectDelay = 1000;
        this.heartbeatInterval = null;
        this.pollingInterval = null;
        this.lastUpdateId = 0;
        this.usePolling = false; // Fallback for browsers that don't support SSE
    }

    /**
     * Connect to real-time updates using Server-Sent Events
     */
    connect(user) {
        if (!user || !user.id) {
            console.error('User information required for real-time connection');
            return Promise.reject(new Error('User information required'));
        }

        return new Promise((resolve, reject) => {
            // Check if browser supports SSE
            if (typeof EventSource === 'undefined') {
                console.warn('EventSource not supported, falling back to polling');
                this.usePolling = true;
                this.startPolling(user);
                resolve();
                return;
            }

            try {
                // Close existing connection
                this.disconnect();

                // Create EventSource connection
                const streamUrl = `/daily-works/realtime/stream`;
                this.eventSource = new EventSource(streamUrl);

                this.eventSource.onopen = () => {
                    console.log('Real-time connection established');
                    this.isConnected = true;
                    this.reconnectAttempts = 0;
                    this.startHeartbeat();
                    resolve();
                };

                this.eventSource.onerror = (error) => {
                    console.error('Real-time connection error:', error);
                    this.isConnected = false;
                    this.stopHeartbeat();
                    
                    if (this.reconnectAttempts < this.maxReconnectAttempts) {
                        this.reconnectAttempts++;
                        console.log(`Attempting to reconnect... (${this.reconnectAttempts}/${this.maxReconnectAttempts})`);
                        setTimeout(() => {
                            this.connect(user);
                        }, this.reconnectDelay * this.reconnectAttempts);
                    } else {
                        console.warn('Max reconnection attempts reached, falling back to polling');
                        this.usePolling = true;
                        this.startPolling(user);
                        resolve();
                    }
                };

                this.eventSource.onmessage = (event) => {
                    try {
                        const data = JSON.parse(event.data);
                        this.handleMessage(data);
                    } catch (error) {
                        console.error('Error parsing real-time message:', error);
                    }
                };

                // Handle specific event types
                this.eventSource.addEventListener('connected', (event) => {
                    const data = JSON.parse(event.data);
                    console.log('Real-time service connected:', data);
                    this.emit('connected', data);
                });

                this.eventSource.addEventListener('daily-work-updated', (event) => {
                    const data = JSON.parse(event.data);
                    this.emit('daily-work-updated', data);
                });

                this.eventSource.addEventListener('cached-update', (event) => {
                    const data = JSON.parse(event.data);
                    this.emit('daily-work-updated', data);
                });

                this.eventSource.addEventListener('heartbeat', (event) => {
                    const data = JSON.parse(event.data);
                    this.emit('heartbeat', data);
                });

            } catch (error) {
                console.error('Failed to create real-time connection:', error);
                this.usePolling = true;
                this.startPolling(user);
                resolve();
            }
        });
    }

    /**
     * Start polling as fallback
     */
    startPolling(user) {
        console.log('Starting polling for real-time updates');
        this.stopHeartbeat();
        
        const poll = async () => {
            try {
                const response = await fetch(`/daily-works/realtime/updates?last_update_id=${this.lastUpdateId}`, {
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                        'Accept': 'application/json',
                    }
                });

                if (response.ok) {
                    const data = await response.json();
                    if (data.success && data.data && data.data.length > 0) {
                        data.data.forEach(update => {
                            this.handleMessage(update);
                            this.lastUpdateId = Math.max(this.lastUpdateId, update.id);
                        });
                    }
                }
            } catch (error) {
                console.error('Polling error:', error);
            }
        };

        // Poll every 5 seconds
        this.pollingInterval = setInterval(poll, 5000);
        
        // Initial poll
        poll();
    }

    /**
     * Handle incoming messages
     */
    handleMessage(data) {
        switch (data.action) {
            case 'connected':
                this.emit('connected', data);
                break;
            case 'daily-work-updated':
            case 'cached-update':
                this.emit('daily-work-updated', data);
                break;
            case 'heartbeat':
                this.emit('heartbeat', data);
                break;
            default:
                console.log('Unknown real-time message:', data);
        }
    }

    /**
     * Start heartbeat to detect connection issues
     */
    startHeartbeat() {
        this.stopHeartbeat();
        this.heartbeatInterval = setInterval(() => {
            if (!this.isConnected && !this.usePolling) {
                console.warn('Heartbeat failed, connection lost');
                this.stopHeartbeat();
            }
        }, 35000); // Check every 35 seconds (heartbeat is every 30)
    }

    /**
     * Stop heartbeat
     */
    stopHeartbeat() {
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
            this.heartbeatInterval = null;
        }
    }

    /**
     * Disconnect from real-time updates
     */
    disconnect() {
        this.isConnected = false;
        this.stopHeartbeat();
        
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
        
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
    }

    /**
     * Add event listener
     */
    on(event, callback) {
        if (!this.listeners.has(event)) {
            this.listeners.set(event, []);
        }
        this.listeners.get(event).push(callback);
    }

    /**
     * Remove event listener
     */
    off(event, callback) {
        if (this.listeners.has(event)) {
            const callbacks = this.listeners.get(event);
            const index = callbacks.indexOf(callback);
            if (index > -1) {
                callbacks.splice(index, 1);
            }
        }
    }

    /**
     * Emit event to listeners
     */
    emit(event, data) {
        if (this.listeners.has(event)) {
            this.listeners.get(event).forEach(callback => {
                try {
                    callback(data);
                } catch (error) {
                    console.error('Error in real-time event listener:', error);
                }
            });
        }
    }

    /**
     * Test real-time connection
     */
    async testConnection() {
        try {
            const response = await fetch('/daily-works/realtime/test', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                    'Accept': 'application/json',
                }
            });

            if (response.ok) {
                const data = await response.json();
                console.log('Test event sent:', data);
                return true;
            }
        } catch (error) {
            console.error('Failed to test real-time connection:', error);
        }
        return false;
    }

    /**
     * Get connection status
     */
    getStatus() {
        return {
            connected: this.isConnected,
            usingPolling: this.usePolling,
            reconnectAttempts: this.reconnectAttempts,
            lastUpdateId: this.lastUpdateId,
        };
    }
}

export default RealtimeService;
