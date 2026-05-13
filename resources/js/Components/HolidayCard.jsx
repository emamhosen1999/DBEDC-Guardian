import React from 'react';
import { Card, Separator, Text } from '@radix-ui/themes';
import { usePage } from "@inertiajs/react";

const HolidayCard = () => {
    const { upcomingHoliday } = usePage().props;
    return (
        <div className="p-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                {/* Upcoming Holiday Card */}
                <div className="flex flex-col">
                    <div className="flex-1 flex flex-col">
                        <Card style={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
                            <div className="p-4">
                                <h3 className="text-lg font-semibold">Upcoming Holiday</h3>
                            </div>
                            <Separator size="4" style={{ margin: '0 0 1rem' }} />
                            <div className="p-4 text-center flex-1 flex flex-col justify-center">
                                {upcomingHoliday ? (
                                    <>
                                        <h4 className="text-base font-medium mb-2">
                                            {
                                                upcomingHoliday.from_date === upcomingHoliday.to_date ?
                                                    new Date(upcomingHoliday.from_date).toLocaleString('en-US', {
                                                        month: 'long',
                                                        day: 'numeric',
                                                        year: 'numeric'
                                                    }) : new Date(upcomingHoliday.from_date).toLocaleString('en-US', {
                                                    month: 'long',
                                                    day: 'numeric',
                                                    year: 'numeric'
                                                }) +" to "+ new Date(upcomingHoliday.to_date).toLocaleString('en-US', {
                                                    month: 'long',
                                                    day: 'numeric',
                                                    year: 'numeric'
                                                })
                                            }
                                            </h4>
                                            <Separator size="4" style={{ margin: '0 0 1rem' }} />
                                            <h3 className="text-xl font-bold mb-2">
                                                {upcomingHoliday.title}
                                            </h3>
                                        </>

                                ) : (
                                    <h4 className="text-base font-medium mb-2">
                                        No upcoming holidays
                                    </h4>
                                )}

                            </div>
                        </Card>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default HolidayCard;
