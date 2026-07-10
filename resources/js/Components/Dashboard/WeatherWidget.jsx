import React, { useEffect, useState } from 'react';
import { Box, Card, Flex, Skeleton, Text } from '@radix-ui/themes';

const WMO = {
    0:  { label: 'Clear sky',       emoji: '☀️',  color: 'amber'  },
    1:  { label: 'Mainly clear',    emoji: '🌤️',  color: 'amber'  },
    2:  { label: 'Partly cloudy',   emoji: '⛅',  color: 'gray'   },
    3:  { label: 'Overcast',        emoji: '☁️',  color: 'gray'   },
    45: { label: 'Foggy',           emoji: '🌫️',  color: 'gray'   },
    48: { label: 'Icy fog',         emoji: '🌫️',  color: 'gray'   },
    51: { label: 'Light drizzle',   emoji: '🌦️',  color: 'blue'   },
    53: { label: 'Drizzle',         emoji: '🌦️',  color: 'blue'   },
    55: { label: 'Heavy drizzle',   emoji: '🌧️',  color: 'blue'   },
    61: { label: 'Light rain',      emoji: '🌧️',  color: 'blue'   },
    63: { label: 'Rain',            emoji: '🌧️',  color: 'blue'   },
    65: { label: 'Heavy rain',      emoji: '🌧️',  color: 'blue'   },
    71: { label: 'Light snow',      emoji: '🌨️',  color: 'cyan'   },
    73: { label: 'Snow',            emoji: '❄️',  color: 'cyan'   },
    75: { label: 'Heavy snow',      emoji: '❄️',  color: 'cyan'   },
    80: { label: 'Rain showers',    emoji: '🌦️',  color: 'blue'   },
    81: { label: 'Showers',         emoji: '🌦️',  color: 'blue'   },
    82: { label: 'Heavy showers',   emoji: '⛈️',  color: 'purple' },
    95: { label: 'Thunderstorm',    emoji: '⛈️',  color: 'purple' },
    96: { label: 'Thunderstorm',    emoji: '⛈️',  color: 'purple' },
    99: { label: 'Thunderstorm',    emoji: '⛈️',  color: 'purple' },
};

function getWmo(code) {
    return WMO[code] ?? { label: 'Unknown', emoji: '🌡️', color: 'gray' };
}

export default function WeatherWidget() {
    const [state, setState] = useState({ status: 'idle', data: null, error: null });

    const fetchWeather = (lat, lon, label = '') => {
        fetch(
            `https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}` +
            `&current_weather=true&hourly=relativehumidity_2m,apparent_temperature` +
            `&forecast_days=1&timezone=auto`
        )
            .then(r => r.json())
            .then(json => {
                const cw = json.current_weather;
                const humidity = json.hourly?.relativehumidity_2m?.[new Date().getHours()] ?? null;
                const feelsLike = json.hourly?.apparent_temperature?.[new Date().getHours()] ?? null;
                setState({
                    status: 'done',
                    data: {
                        temp: Math.round(cw.temperature),
                        windspeed: Math.round(cw.windspeed),
                        code: cw.weathercode,
                        humidity,
                        feelsLike: feelsLike != null ? Math.round(feelsLike) : null,
                        locationName: label,
                    },
                    error: null,
                });
            })
            .catch(() => {
                // If fetching fails, fall back to Dhaka weather
                if (label !== 'Dhaka') {
                    fetchWeather(23.8103, 90.4125, 'Dhaka');
                } else {
                    setState({ status: 'error', data: null, error: 'Weather fetch failed' });
                }
            });
    };

    useEffect(() => {
        if (!navigator.geolocation) {
            fetchWeather(23.8103, 90.4125, 'Dhaka');
            return;
        }

        setState(s => ({ ...s, status: 'locating' }));

        navigator.geolocation.getCurrentPosition(
            ({ coords }) => {
                const { latitude: lat, longitude: lon } = coords;
                setState(s => ({ ...s, status: 'fetching' }));
                fetchWeather(lat, lon, 'Your Location');
            },
            () => {
                // Fallback to Dhaka
                setState(s => ({ ...s, status: 'fetching' }));
                fetchWeather(23.8103, 90.4125, 'Dhaka');
            },
            { timeout: 5000 }
        );
    }, []);

    const isLoading = state.status === 'idle' || state.status === 'locating' || state.status === 'fetching';

    if (state.status === 'error') {
        return (
            <Card style={{ height: '100%' }}>
                <Flex direction="column" justify="center" style={{ height: '100%' }}>
                    <Text size="4" style={{ lineHeight: 1 }}>📍</Text>
                    <Text size="2" color="gray" mt="2">{state.error}</Text>
                </Flex>
            </Card>
        );
    }

    const wmo = state.data ? getWmo(state.data.code) : null;

    return (
        <Card style={{ height: '100%' }}>
            <Flex direction="column" gap="2" style={{ height: '100%' }}>
                <Flex align="center" justify="between">
                    <Text size="1" color="gray" style={{ textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                        Weather
                    </Text>
                    {state.data?.locationName && (
                        <Badge size="1" color="indigo" variant="soft">
                            📍 {state.data.locationName}
                        </Badge>
                    )}
                </Flex>

                {isLoading ? (
                    <Flex direction="column" gap="2" mt="1">
                        <Skeleton style={{ width: 80, height: 36 }} />
                        <Skeleton style={{ width: 110, height: 16 }} />
                    </Flex>
                ) : (
                    <>
                        <Flex align="center" gap="2">
                            <Text style={{ fontSize: 'clamp(22px, 3vw, 32px)', lineHeight: 1 }}>{wmo.emoji}</Text>
                            <Flex direction="column" gap="0">
                                <Text size={{ initial: '4', md: '6' }} weight="bold" style={{ lineHeight: 1 }}>
                                    {state.data.temp}°C
                                </Text>
                                <Text size={{ initial: '1', sm: '1' }} color="gray">{wmo.label}</Text>
                            </Flex>
                        </Flex>

                        <Flex gap={{ initial: '2', sm: '3' }} wrap="wrap" mt="1">
                            {state.data.feelsLike != null && (
                                <Flex direction="column" gap="0">
                                    <Text size="1" color="gray">Feels like</Text>
                                    <Text size="2" weight="medium">{state.data.feelsLike}°C</Text>
                                </Flex>
                            )}
                            {state.data.humidity != null && (
                                <Flex direction="column" gap="0">
                                    <Text size="1" color="gray">Humidity</Text>
                                    <Text size="2" weight="medium">{state.data.humidity}%</Text>
                                </Flex>
                            )}
                            <Flex direction="column" gap="0">
                                <Text size="1" color="gray">Wind</Text>
                                <Text size="2" weight="medium">{state.data.windspeed} km/h</Text>
                            </Flex>
                        </Flex>
                    </>
                )}
            </Flex>
        </Card>
    );
}
