# Daily Works System - Deployment Setup Guide

## Overview
This guide covers the deployment of the enhanced daily works system using **Laravel's built-in features** for optimal performance with **zero external dependencies**.

## Architecture Summary

**System uses Laravel-native components only:**
- ✅ **Caching:** Laravel File Cache (no Redis required)
- ✅ **Real-time:** Server-Sent Events (SSE) + Polling fallback (no WebSocket server required)
- ✅ **Events:** Laravel Event System (no Pusher required)
- ✅ **Database:** Standard Laravel migrations
- ✅ **Frontend:** Server-Sent Events with polling fallback
- ✅ **Mobile:** Polling-based real-time updates

## Prerequisites

### 1. Laravel Environment Setup
Ensure your Laravel application is properly configured:
- Laravel 8+ installed
- Database configured (MySQL/PostgreSQL/SQLite)
- Basic Laravel features working

### 2. Cache Configuration (Laravel Built-in)
The system uses Laravel's built-in file caching by default - **no external setup required**.

#### Optional: Database Cache
If you prefer database caching over file caching:
```env
CACHE_DRIVER=database
```

Then create the cache table:
```bash
php artisan cache:table
php artisan migrate
```

## Environment Configuration

### Update `.env` file
```env
# Cache Configuration (Laravel built-in)
CACHE_DRIVER=file
# Or use: CACHE_DRIVER=database

# Session Configuration
SESSION_DRIVER=file

# Queue Configuration (optional, for background processing)
QUEUE_CONNECTION=database

# Application URL
APP_URL=https://aero-enterprise-suite.test

# Broadcasting (not needed for SSE, but Laravel requires it)
BROADCAST_DRIVER=log
```

## Database Setup

### Run Migrations
```bash
# Audit trail migration
php artisan migrate --path=database/migrations/2026_04_28_000001_create_daily_work_audits_table.php

# Run any remaining migrations
php artisan migrate
```

### Verify Database
```bash
# Check if audit table exists
php artisan tinker
>>> Schema::hasTable('daily_work_audits')
```

## Real-Time Setup

### Server-Sent Events (SSE)
The system uses Laravel's built-in Server-Sent Events for real-time updates - **no external WebSocket server required**.

**SSE Endpoint:** `/daily-works/realtime/stream`
**Polling Endpoint:** `/daily-works/realtime/updates` (fallback)

**How it works:**
1. Web browsers connect to SSE endpoint for real-time updates
2. Mobile apps use polling endpoint every 10 seconds
3. Laravel Events handle the real-time logic
4. File cache stores pending updates for polling

**Testing Real-Time:**
```bash
# Test SSE endpoint (requires authentication)
curl -H "Authorization: Bearer YOUR_TOKEN" \
  https://aero-enterprise-suite.test/daily-works/realtime/stream

# Test polling endpoint (requires authentication)
curl -H "Authorization: Bearer YOUR_TOKEN" \
  https://aero-enterprise-suite.test/daily-works/realtime/updates
```

## Frontend Setup

### Install Dependencies
```bash
# Install any missing dependencies
npm install
```

**Note:** No Socket.IO client required - RealtimeService uses native EventSource API.

### RealtimeService Integration
The frontend uses the new RealtimeService which automatically:
- Uses Server-Sent Events (SSE) for modern browsers
- Falls back to polling for unsupported browsers
- Handles reconnection automatically
- Manages event listeners efficiently

**No additional setup required** - the service is already integrated in DailyWorks.jsx.

## Mobile App Setup

### Update API Configuration
Update mobile app API configuration to use polling-based real-time service:
```javascript
// In src/api/client.js or similar
const API_BASE_URL = 'http://localhost:8000/api/v1';
```

**Note:** No WebSocket URL required - mobile app uses polling endpoint.

### RealtimeService Integration
The mobile app uses the updated websocketService (now RealtimeService) which:
- Polls `/api/v1/daily-works/realtime/updates` every 10 seconds
- Automatically handles reconnection
- Manages event listeners
- No Socket.IO dependency required

**No additional setup required** - the service is already updated in `src/services/websocketService.js`.

### Install Mobile Dependencies
```bash
cd c:\laragon\www\dbedc-mobile-app
npm install
```

**Note:** No Socket.IO client required - uses native fetch for polling.

## Testing the Setup

### 1. Test Cache Functionality
```bash
php artisan tinker
>>> Cache::put('test', 'value', 60)
>>> Cache::get('test')
```

### 2. Test Real-Time Endpoints
```bash
# Test mobile endpoints (requires authentication)
curl https://aero-enterprise-suite.test/mobile/daily-works/statistics

# Test recent works endpoint
curl https://aero-enterprise-suite.test/mobile/daily-works/recent
```

### 3. Test New API Endpoints
```bash
# Test mobile endpoints
curl http://localhost:8000/mobile/daily-works/statistics

# Test bulk operations (if routes are enabled)
curl -X POST http://localhost:8000/daily-works/bulk/operations
```

## Deployment Summary

**System Architecture:**
- ✅ Zero external dependencies
- ✅ Laravel File Cache (no Redis)
- ✅ Server-Sent Events (no WebSocket server)
- ✅ Laravel Event System (no Pusher)
- ✅ Native browser APIs (no Socket.IO)

**Key Features:**
- Real-time updates via SSE + polling fallback
- Mobile-optimized API endpoints
- Comprehensive audit trail
- Business intelligence analytics
- Simplified bulk operations

**Production Ready:** Yes - All components use Laravel built-in features only.

### 4. Run Test Suite
```bash
# Run all tests
php artisan test

# Run specific tests
php artisan test --filter=DailyWorkCacheServiceTest
php artisan test --filter=DailyWorkControllerTest
php artisan test --filter=DailyWorkAuditServiceTest
```

## Performance Testing

### Cache Performance
```bash
# Test cache hit rates
php artisan tinker
>>> $service = new \App\Services\DailyWork\DailyWorkCacheService();
>>> $service->getCachedStatistics();
>>> // Check Redis monitor for cache hits
```

### API Response Times
```bash
# Test with curl and measure response times
time curl -w "@curl-format.txt" http://localhost:8000/mobile/daily-works/statistics
```

## Monitoring Setup

### Redis Monitoring
```bash
# Redis CLI monitoring
redis-cli monitor

# Redis info
redis-cli info memory
redis-cli info stats
```

### WebSocket Monitoring
The Laravel Echo Server provides built-in monitoring at http://localhost:6001

### Application Monitoring
```bash
# Laravel Telescope (if installed)
php artisan telescope:install
php artisan migrate
```

## Troubleshooting

### Common Issues

#### 1. Redis Connection Failed
```bash
# Check if Redis is running
redis-cli ping

# Check Redis configuration
php artisan config:cache
php artisan cache:clear
```

#### 2. WebSocket Connection Failed
```bash
# Check Echo Server status
laravel-echo-server status

# Check port availability
netstat -an | findstr 6001
```

#### 3. Migration Errors
```bash
# Reset and rerun migrations
php artisan migrate:rollback --step=1
php artisan migrate
```

#### 4. Cache Issues
```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

## Production Considerations

### 1. Redis Configuration
- Set up Redis persistence
- Configure Redis memory limits
- Set up Redis clustering for high availability

### 2. WebSocket Server
- Use PM2 for process management
- Set up SSL/TLS for production
- Configure load balancing

### 3. Application Optimization
- Enable OPcache
- Configure queue workers
- Set up monitoring and alerting

## Security Considerations

### 1. Redis Security
- Set Redis password
- Bind Redis to localhost only
- Use Redis AUTH in production

### 2. WebSocket Security
- Use WSS (WebSocket Secure) in production
- Implement proper authentication
- Rate limiting for WebSocket connections

### 3. API Security
- Validate all inputs
- Implement rate limiting
- Use HTTPS for all API calls

## Deployment Checklist

- [ ] Redis server installed and running
- [ ] Laravel Echo Server configured and running
- [ ] Database migrations completed
- [ ] Environment variables configured
- [ ] Frontend WebSocket integration tested
- [ ] Mobile app API endpoints tested
- [ ] Test suite passing
- [ ] Performance benchmarks recorded
- [ ] Monitoring setup completed
- [ ] Security measures implemented

## Support and Maintenance

### Daily Tasks
- Monitor Redis memory usage
- Check WebSocket connection health
- Review application logs

### Weekly Tasks
- Clear old cache entries
- Update monitoring dashboards
- Review performance metrics

### Monthly Tasks
- Update dependencies
- Review security patches
- Backup configurations

## Next Steps

After completing this setup guide:

1. **Test all new features** thoroughly
2. **Train users** on new functionality
3. **Monitor performance** in production
4. **Gather feedback** and optimize
5. **Plan future enhancements**

For additional support or issues, refer to the project documentation or contact the development team.
