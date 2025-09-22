(function () {
    const page = document.body.dataset.page;
    if (page === 'home') {
        initCalendarPage();
    } else if (page === 'stats') {
        initStatsPage();
    }

    function initCalendarPage() {
        const state = {
            month: new Date().getMonth() + 1,
            year: new Date().getFullYear(),
            editing: false,
            participants: [],
            events: [],
            pendingRange: null,
        };

        const calendarContainer = document.getElementById('calendar-container');
        const currentMonthEl = document.getElementById('current-month');
        const prevMonthBtn = document.getElementById('prev-month');
        const nextMonthBtn = document.getElementById('next-month');
        const editToggle = document.getElementById('edit-toggle');
        const addParticipantBtn = document.getElementById('add-participant');
        const infoMessage = document.getElementById('info-message');
        const distributeBtn = document.getElementById('distribute');
        const eventMenu = document.getElementById('event-menu');
        const modal = createModal();

        const eventLabels = {
            duty: 'Х',
            important: 'o',
            vacation: 'Отпуск',
            trip: 'Командировка',
            sick: 'Больничный',
        };

        const rangeTypes = ['vacation', 'trip', 'sick'];

        loadCalendar();

        prevMonthBtn.addEventListener('click', () => {
            changeMonth(-1);
        });
        nextMonthBtn.addEventListener('click', () => {
            changeMonth(1);
        });
        editToggle.addEventListener('change', (event) => {
            state.editing = event.target.checked;
            if (!state.editing) {
                clearPendingRange();
                hideEventMenu();
                infoMessage.textContent = '';
            }
            renderCalendar();
        });
        addParticipantBtn.addEventListener('click', () => {
            if (!state.editing) return;
            const name = prompt('Введите фамилию участника');
            if (name) {
                addParticipant(name.trim());
            }
        });
        distributeBtn.addEventListener('click', handleAutoAssign);

        document.addEventListener('click', (event) => {
            if (eventMenu.classList.contains('hidden')) {
                return;
            }
            if (!eventMenu.contains(event.target)) {
                hideEventMenu();
            }
        });

        Array.from(eventMenu.querySelectorAll('button')).forEach((button) => {
            button.addEventListener('click', () => {
                const type = button.dataset.type;
                const { participantId, date } = eventMenu.dataset;
                hideEventMenu();
                handleEventCreation(type, participantId, date);
            });
        });

        function changeMonth(delta) {
            state.month += delta;
            if (state.month < 1) {
                state.month = 12;
                state.year -= 1;
            } else if (state.month > 12) {
                state.month = 1;
                state.year += 1;
            }
            clearPendingRange();
            infoMessage.textContent = '';
            loadCalendar();
        }

        function loadCalendar() {
            const formData = new FormData();
            formData.append('action', 'get_calendar');
            formData.append('month', String(state.month));
            formData.append('year', String(state.year));

            fetch('api.php', {
                method: 'POST',
                body: formData,
            })
                .then((response) => response.json())
                .then((data) => {
                    state.participants = data.participants || [];
                    state.events = data.events || [];
                    renderCalendar();
                })
                .catch(() => {
                    infoMessage.textContent = 'Не удалось загрузить данные.';
                });
        }

        function renderCalendar() {
            const date = new Date(state.year, state.month - 1, 1);
            currentMonthEl.textContent = date.toLocaleString('ru-RU', {
                month: 'long',
                year: 'numeric',
            });

            calendarContainer.innerHTML = '';
            const table = document.createElement('table');
            table.className = 'calendar-table';

            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');
            const nameHeader = document.createElement('th');
            nameHeader.textContent = 'ФИО';
            headerRow.appendChild(nameHeader);

            const daysInMonth = new Date(state.year, state.month, 0).getDate();
            for (let day = 1; day <= daysInMonth; day++) {
                const th = document.createElement('th');
                th.textContent = day;
                const dateKey = formatDate(state.year, state.month, day);
                if (isWeekend(dateKey)) {
                    th.classList.add('weekend');
                }
                headerRow.appendChild(th);
            }
            thead.appendChild(headerRow);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');
            const eventMap = buildEventMap();

            state.participants.forEach((participant, index) => {
                const row = document.createElement('tr');
                const nameCell = document.createElement('td');
                nameCell.textContent = participant.name;
                if (state.editing) {
                    const actions = document.createElement('div');
                    actions.className = 'participant-actions';

                    const upBtn = document.createElement('button');
                    upBtn.textContent = '▲';
                    upBtn.title = 'Выше';
                    upBtn.addEventListener('click', () => moveParticipant(index, -1));

                    const downBtn = document.createElement('button');
                    downBtn.textContent = '▼';
                    downBtn.title = 'Ниже';
                    downBtn.addEventListener('click', () => moveParticipant(index, 1));

                    const removeBtn = document.createElement('button');
                    removeBtn.textContent = '✖';
                    removeBtn.title = 'Удалить';
                    removeBtn.addEventListener('click', () => deleteParticipant(participant.id));

                    if (index === 0) {
                        upBtn.disabled = true;
                    }
                    if (index === state.participants.length - 1) {
                        downBtn.disabled = true;
                    }

                    actions.append(upBtn, downBtn, removeBtn);
                    nameCell.appendChild(actions);
                }
                row.appendChild(nameCell);

                for (let day = 1; day <= daysInMonth; day++) {
                    const cell = document.createElement('td');
                    const dateKey = formatDate(state.year, state.month, day);
                    cell.dataset.date = dateKey;
                    cell.dataset.participantId = participant.id;

                    if (isWeekend(dateKey)) {
                        cell.classList.add('weekend');
                    }

                    const eventInfo = (eventMap[participant.id] || {})[dateKey];
                    if (eventInfo) {
                        const { event, isStart, isEnd } = eventInfo;
                        cell.dataset.eventId = event.id;
                        cell.classList.add('has-event');
                        cell.classList.add('event-' + event.type);
                        cell.title = eventLabels[event.type] || '';

                        if (event.type === 'duty') {
                            cell.textContent = eventLabels.duty;
                        } else if (event.type === 'important') {
                            cell.textContent = eventLabels.important;
                        } else if (rangeTypes.includes(event.type)) {
                            cell.classList.add('range-' + event.type);
                            if (isStart) {
                                cell.classList.add('range-start');
                                cell.textContent = eventLabels[event.type];
                            } else if (isEnd) {
                                cell.classList.add('range-end');
                            } else {
                                cell.classList.add('range-middle');
                            }
                        }
                    } else {
                        cell.classList.add('editable');
                    }

                    cell.addEventListener('click', (event) => handleCellClick(event, cell));
                    row.appendChild(cell);
                }

                tbody.appendChild(row);
            });

            table.appendChild(tbody);
            calendarContainer.appendChild(table);
        }

        function buildEventMap() {
            const map = {};
            state.events.forEach((event) => {
                if (!map[event.participant_id]) {
                    map[event.participant_id] = {};
                }
                const start = new Date(event.start_date);
                const end = new Date(event.end_date);
                for (let date = new Date(start); date <= end; date.setDate(date.getDate() + 1)) {
                    const year = date.getFullYear();
                    const month = date.getMonth() + 1;
                    if (year !== state.year || month !== state.month) {
                        continue;
                    }
                    const day = date.getDate();
                    const dateKey = formatDate(year, month, day);
                    map[event.participant_id][dateKey] = {
                        event,
                        isStart: dateKey === event.start_date,
                        isEnd: dateKey === event.end_date,
                    };
                }
            });
            return map;
        }

        function handleCellClick(event, cell) {
            event.stopPropagation();
            if (!state.editing) {
                return;
            }

            const eventId = cell.dataset.eventId;
            const participantId = cell.dataset.participantId;
            const date = cell.dataset.date;

            if (state.pendingRange) {
                if (state.pendingRange.participantId !== participantId) {
                    infoMessage.textContent = 'Выберите окончание в той же строке.';
                    return;
                }
                if (date <= state.pendingRange.startDate) {
                    infoMessage.textContent = 'Дата окончания должна быть позже.';
                    return;
                }
                saveEvent(participantId, state.pendingRange.type, state.pendingRange.startDate, date);
                clearPendingRange();
                return;
            }

            if (eventId) {
                modal
                    .confirm('Удалить выбранное событие?')
                    .then((confirmed) => {
                        if (confirmed) {
                            deleteEvent(eventId);
                        }
                    });
                return;
            }

            showEventMenu(cell, participantId, date, event);
        }

        function showEventMenu(cell, participantId, date, originalEvent) {
            const rect = cell.getBoundingClientRect();
            eventMenu.style.top = `${rect.bottom + window.scrollY}px`;
            eventMenu.style.left = `${rect.left + window.scrollX}px`;
            eventMenu.dataset.participantId = participantId;
            eventMenu.dataset.date = date;
            eventMenu.classList.remove('hidden');
        }

        function hideEventMenu() {
            eventMenu.classList.add('hidden');
            delete eventMenu.dataset.participantId;
            delete eventMenu.dataset.date;
        }

        function handleEventCreation(type, participantId, date) {
            if (rangeTypes.includes(type)) {
                state.pendingRange = {
                    type,
                    participantId,
                    startDate: date,
                };
                const cell = findCell(participantId, date);
                if (cell) {
                    cell.classList.add('pending-selection');
                }
                infoMessage.textContent = 'Выберите день окончания события в той же строке.';
                return;
            }

            saveEvent(participantId, type, date, date);
        }

        function findCell(participantId, date) {
            return calendarContainer.querySelector(`td[data-participant-id="${participantId}"][data-date="${date}"]`);
        }

        function clearPendingRange() {
            if (state.pendingRange) {
                const cell = findCell(state.pendingRange.participantId, state.pendingRange.startDate);
                if (cell) {
                    cell.classList.remove('pending-selection');
                }
            }
            state.pendingRange = null;
        }

        function addParticipant(name) {
            const formData = new FormData();
            formData.append('action', 'add_participant');
            formData.append('name', name);

            fetch('api.php', {
                method: 'POST',
                body: formData,
            })
                .then((response) => response.json())
                .then(() => {
                    loadCalendar();
                })
                .catch(() => {
                    infoMessage.textContent = 'Не удалось добавить участника.';
                });
        }

        function deleteParticipant(id) {
            modal
                .confirm('Удалить участника и все его события?')
                .then((confirmed) => {
                    if (!confirmed) return;
                    const formData = new FormData();
                    formData.append('action', 'delete_participant');
                    formData.append('id', id);
                    fetch('api.php', {
                        method: 'POST',
                        body: formData,
                    })
                        .then((response) => response.json())
                        .then(() => {
                            loadCalendar();
                        })
                        .catch(() => {
                            infoMessage.textContent = 'Не удалось удалить участника.';
                        });
                });
        }

        function moveParticipant(index, delta) {
            const newIndex = index + delta;
            if (newIndex < 0 || newIndex >= state.participants.length) {
                return;
            }
            const reordered = [...state.participants];
            const [moved] = reordered.splice(index, 1);
            reordered.splice(newIndex, 0, moved);
            state.participants = reordered;
            renderCalendar();
            saveParticipantOrder();
        }

        function saveParticipantOrder() {
            const formData = new FormData();
            formData.append('action', 'reorder_participants');
            state.participants.forEach((participant) => {
                formData.append('order[]', participant.id);
            });
            fetch('api.php', {
                method: 'POST',
                body: formData,
            }).catch(() => {
                infoMessage.textContent = 'Не удалось сохранить порядок участников.';
            });
        }

        function saveEvent(participantId, type, startDate, endDate) {
            const formData = new FormData();
            formData.append('action', 'save_event');
            formData.append('participant_id', participantId);
            formData.append('type', type);
            formData.append('start_date', startDate);
            formData.append('end_date', endDate);

            fetch('api.php', {
                method: 'POST',
                body: formData,
            })
                .then((response) => {
                    if (!response.ok) {
                        return response.json().then((data) => {
                            throw new Error(data.error || 'Ошибка сохранения события');
                        });
                    }
                    return response.json();
                })
                .then(() => {
                    infoMessage.textContent = '';
                    loadCalendar();
                })
                .catch((error) => {
                    infoMessage.textContent = error.message;
                    clearPendingRange();
                });
        }

        function deleteEvent(eventId) {
            const formData = new FormData();
            formData.append('action', 'delete_event');
            formData.append('id', eventId);

            fetch('api.php', {
                method: 'POST',
                body: formData,
            })
                .then(() => {
                    loadCalendar();
                })
                .catch(() => {
                    infoMessage.textContent = 'Не удалось удалить событие.';
                });
        }

        function handleAutoAssign() {
            const formData = new FormData();
            formData.append('action', 'auto_assign');
            formData.append('month', state.month);
            formData.append('year', state.year);
            formData.append('force', 'false');

            fetch('api.php', {
                method: 'POST',
                body: formData,
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data.needs_confirm) {
                        modal.confirm('Перезаписать существующие дежурства?').then((confirmed) => {
                            if (!confirmed) return;
                            const confirmData = new FormData();
                            confirmData.append('action', 'auto_assign');
                            confirmData.append('month', state.month);
                            confirmData.append('year', state.year);
                            confirmData.append('force', 'true');
                            fetch('api.php', {
                                method: 'POST',
                                body: confirmData,
                            })
                                .then((response) => response.json())
                                .then(handleAutoAssignResult)
                                .catch(() => {
                                    infoMessage.textContent = 'Не удалось распределить дежурства.';
                                });
                        });
                    } else {
                        handleAutoAssignResult(data);
                    }
                })
                .catch(() => {
                    infoMessage.textContent = 'Не удалось распределить дежурства.';
                });
        }

        function handleAutoAssignResult(data) {
            if (data.skipped && data.skipped.length > 0) {
                infoMessage.textContent = 'Не удалось назначить дежурство на дни: ' + data.skipped.join(', ');
            } else {
                infoMessage.textContent = 'Дежурства успешно распределены.';
            }
            loadCalendar();
        }
    }

    function initStatsPage() {
        const yearSelect = document.getElementById('stats-year');
        const tbody = document.getElementById('stats-body');
        const state = {
            year: new Date().getFullYear(),
        };

        yearSelect.addEventListener('change', () => {
            state.year = parseInt(yearSelect.value, 10);
            loadStats();
        });

        loadStats();

        function loadStats() {
            const formData = new FormData();
            formData.append('action', 'get_statistics');
            formData.append('year', state.year);

            fetch('api.php', {
                method: 'POST',
                body: formData,
            })
                .then((response) => response.json())
                .then((data) => {
                    renderStats(data);
                })
                .catch(() => {
                    tbody.innerHTML = '<tr><td colspan="11">Не удалось загрузить статистику</td></tr>';
                });
        }

        function renderStats(data) {
            if (data.years) {
                yearSelect.innerHTML = '';
                data.years.forEach((year) => {
                    const option = document.createElement('option');
                    option.value = year;
                    option.textContent = year;
                    if (parseInt(year, 10) === parseInt(data.year, 10)) {
                        option.selected = true;
                    }
                    yearSelect.appendChild(option);
                });
            }

            const weekdayOrder = [1, 2, 3, 4, 5, 6, 0];
            tbody.innerHTML = '';
            (data.data || []).forEach((row) => {
                const tr = document.createElement('tr');
                const nameCell = document.createElement('td');
                nameCell.textContent = row.name;
                tr.appendChild(nameCell);

                weekdayOrder.forEach((weekday) => {
                    const td = document.createElement('td');
                    td.textContent = row.weekdays ? row.weekdays[weekday] || 0 : 0;
                    tr.appendChild(td);
                });

                const vacation = document.createElement('td');
                vacation.textContent = row.vacation || 0;
                tr.appendChild(vacation);

                const sick = document.createElement('td');
                sick.textContent = row.sick || 0;
                tr.appendChild(sick);

                const total = document.createElement('td');
                total.textContent = row.total || 0;
                tr.appendChild(total);

                tbody.appendChild(tr);
            });

            if (!tbody.children.length) {
                tbody.innerHTML = '<tr><td colspan="11">Нет данных</td></tr>';
            }
        }
    }

    function createModal() {
        const modal = document.getElementById('confirm-modal');
        const messageEl = document.getElementById('modal-message');
        const confirmBtn = document.getElementById('modal-confirm');
        const cancelBtn = document.getElementById('modal-cancel');

        let resolver = null;

        confirmBtn.addEventListener('click', () => {
            hideModal(true);
        });
        cancelBtn.addEventListener('click', () => {
            hideModal(false);
        });

        function showModal(message) {
            messageEl.textContent = message;
            modal.classList.remove('hidden');
            return new Promise((resolve) => {
                resolver = resolve;
            });
        }

        function hideModal(result) {
            modal.classList.add('hidden');
            if (resolver) {
                resolver(result);
                resolver = null;
            }
        }

        return {
            confirm(message) {
                return showModal(message);
            },
        };
    }

    function formatDate(year, month, day) {
        return [
            year.toString().padStart(4, '0'),
            month.toString().padStart(2, '0'),
            day.toString().padStart(2, '0'),
        ].join('-');
    }

    function isWeekend(dateString) {
        const date = new Date(dateString + 'T00:00:00');
        const day = date.getDay();
        return day === 0 || day === 6;
    }
})();
