(function (window) {
    'use strict';

    const colors = {
        available: '#138a43',
        occupied: '#c2410c',
        faulted: '#b42318',
        unknown: '#d0d5dd'
    };

    const labels = {
        available: 'Vrij',
        occupied: 'Bezet',
        faulted: 'Buiten gebruik',
        unknown: 'Onbekend'
    };

    const charts = new WeakMap();

    function formatPercent(value) {
        return Number(value || 0).toFixed(1).replace('.', ',') + '%';
    }

    function formatDuration(seconds) {
        const totalMinutes = Math.max(0, Math.round(Number(seconds || 0) / 60));
        const hours = Math.floor(totalMinutes / 60);
        const minutes = totalMinutes % 60;

        if (hours > 0 && minutes > 0) {
            return hours + ' u ' + minutes + ' m';
        }

        if (hours > 0) {
            return hours + ' u';
        }

        return minutes + ' m';
    }

    function periodHours(data) {
        const seconds = Number(data.period && data.period.seconds ? data.period.seconds : 86400);
        return Math.max(1, seconds / 3600);
    }

    function formatPeriod(seconds) {
        const totalHours = Math.round(Number(seconds || 0) / 3600);

        if (totalHours <= 48) {
            return totalHours + ' uur';
        }

        if (totalHours % 24 === 0) {
            const days = totalHours / 24;
            return days === 1 ? '1 dag' : days + ' dagen';
        }

        return totalHours + ' uur';
    }

    function segmentPosition(value, from, span, max) {
        return Math.max(0, Math.min(max, ((value - from) / span) * max));
    }

    function tickStep(maxHours) {
        if (maxHours <= 12) {
            return 3;
        }

        if (maxHours <= 24) {
            return 6;
        }

        if (maxHours <= 48) {
            return 12;
        }

        if (maxHours <= 168) {
            return 24;
        }

        if (maxHours <= 744) {
            return 120;
        }

        return 336;
    }

    function formatAxisTick(value, maxHours) {
        const remaining = Math.max(0, Math.round(maxHours - Number(value)));

        if (remaining === 0) {
            return 'nu';
        }

        if (maxHours <= 72) {
            return '-' + remaining;
        }

        if (remaining % 24 === 0) {
            return '-' + Math.round(remaining / 24) + 'd';
        }

        return '-' + remaining + 'u';
    }

    function render(canvas, data) {
        if (!window.Chart) {
            throw new Error('Chart.js niet geladen');
        }

        const from = new Date(data.period.from).getTime();
        const to = new Date(data.period.to).getTime();
        const span = Math.max(1, to - from);
        const maxHours = periodHours(data);
        const segments = data.segments || [];
        const points = [];
        const pointColors = [];

        for (const segment of segments) {
            const start = segmentPosition(new Date(segment.from).getTime(), from, span, maxHours);
            const end = segmentPosition(new Date(segment.to).getTime(), from, span, maxHours);
            const bucket = segment.status_bucket || 'unknown';

            if (end <= start) {
                continue;
            }

            points.push({
                x: [start, end],
                y: 'periode',
                segment: segment
            });
            pointColors.push(colors[bucket] || colors.unknown);
        }

        const existingChart = charts.get(canvas);
        if (existingChart) {
            existingChart.destroy();
        }

        const chart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: ['periode'],
                datasets: [{
                    label: 'Beschikbaarheid',
                    data: points,
                    backgroundColor: pointColors,
                    borderColor: '#ffffff',
                    borderWidth: 0,
                    hoverBorderWidth: 1,
                    borderRadius: function (context) {
                        const count = context.dataset.data.length;
                        const first = context.dataIndex === 0;
                        const last = context.dataIndex === count - 1;

                        return {
                            topLeft: first ? 6 : 0,
                            bottomLeft: first ? 6 : 0,
                            topRight: last ? 6 : 0,
                            bottomRight: last ? 6 : 0
                        };
                    },
                    borderSkipped: false,
                    barThickness: 26
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 350
                },
                parsing: {
                    xAxisKey: 'x',
                    yAxisKey: 'y'
                },
                layout: {
                    padding: {
                        top: 4,
                        right: 4,
                        bottom: 0,
                        left: 0
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        displayColors: false,
                        callbacks: {
                            title: function () {
                                return 'Beschikbaarheid';
                            },
                            label: function (context) {
                                const segment = context.raw.segment || {};
                                const bucket = segment.status_bucket || 'unknown';
                                const label = segment.status_label || labels[bucket] || 'Onbekend';
                                return label + ': ' + formatDuration(segment.duration_seconds || 0);
                            },
                            afterLabel: function (context) {
                                const segment = context.raw.segment || {};
                                const start = segment.from ? new Date(segment.from) : null;
                                const end = segment.to ? new Date(segment.to) : null;

                                if (!start || !end) {
                                    return '';
                                }

                                return start.toLocaleString('nl-NL', {
                                    day: '2-digit',
                                    month: '2-digit',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                }) + ' - ' + end.toLocaleString('nl-NL', {
                                    day: '2-digit',
                                    month: '2-digit',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                });
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        type: 'linear',
                        min: 0,
                        max: maxHours,
                        offset: false,
                        grid: {
                            color: 'rgba(102, 112, 133, 0.14)',
                            drawBorder: false
                        },
                        ticks: {
                            stepSize: tickStep(maxHours),
                            color: '#667085',
                            font: {
                                size: 11
                            },
                            callback: function (value) {
                                return formatAxisTick(value, maxHours);
                            }
                        }
                    },
                    y: {
                        display: false,
                        grid: {
                            display: false,
                            drawBorder: false
                        }
                    }
                }
            }
        });

        charts.set(canvas, chart);

        return chart;
    }

    async function load(canvas, options) {
        const settings = options || {};
        const favoriteId = settings.favoriteId || canvas.dataset.availabilityFavorite || canvas.dataset.availabilityChart;
        const period = settings.period || canvas.dataset.availabilityPeriod || '48h';
        const summary = settings.summaryElement || null;

        try {
            const response = await fetch(
                'api/availability.php?favorite_id=' + encodeURIComponent(favoriteId)
                + '&period=' + encodeURIComponent(period),
                {headers: {Accept: 'application/json'}}
            );
            const data = await response.json();

            if (!data.success) {
                const message = data.error && data.error.message ? data.error.message : 'API-fout';
                throw new Error(message);
            }

            render(canvas, data);

            if (summary) {
                const available = data.summary && data.summary.available ? data.summary.available.percentage : 0;
                const occupied = data.summary && data.summary.occupied ? data.summary.occupied.percentage : 0;
                summary.textContent = 'vrij ' + formatPercent(available) + ' / bezet ' + formatPercent(occupied);
            }

            if (typeof settings.onData === 'function') {
                settings.onData(data);
            }

            return data;
        } catch (error) {
            if (summary) {
                summary.textContent = error.message;
            }

            if (typeof settings.onError === 'function') {
                settings.onError(error);
            }

            throw error;
        }
    }

    window.InChargeAvailabilityChart = {
        colors: colors,
        labels: labels,
        formatDuration: formatDuration,
        formatPercent: formatPercent,
        formatPeriod: formatPeriod,
        load: load,
        render: render
    };
})(window);
