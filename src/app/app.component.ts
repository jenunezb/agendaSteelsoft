import { CommonModule } from '@angular/common';
import { Component, OnInit, inject } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { catchError, forkJoin, of } from 'rxjs';
import { AgendaApiService } from './agenda-api.service';
import { Activity, CalendarDay, FinancialEntry, GeneralPending, WeekGroup } from './app.models';

type ViewMode = 'month' | 'week';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './app.component.html',
  styleUrl: './app.component.css'
})
export class AppComponent implements OnInit {
  private readonly agendaApi = inject(AgendaApiService);
  protected readonly weekDayNames = ['Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'];
  protected readonly fullWeekDayNames = ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'];
  protected readonly monthNames = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre'
  ];
  protected readonly timeOptions = this.buildTimeOptions();
  protected readonly weeklyTimeOptions = this.buildWeeklyTimeOptions();
  protected readonly weeklyHourStart = 8;
  protected readonly weeklyHourEnd = 18;
  protected readonly weeklyRowHeight = 72;
  protected readonly assigneeOptions = ['Jhonatan', 'Julian', 'Steelsoft'];

  protected currentMonth = this.startOfMonth(new Date());
  protected selectedDate = this.toIsoDate(this.normalizeCalendarDate(new Date()));
  protected isActivityPanelOpen = false;
  protected isPendingPanelOpen = false;
  protected isFinancePanelOpen = false;
  protected viewMode: ViewMode = 'month';
  protected editingActivityId: number | null = null;
  protected editingGeneralPendingId: number | null = null;
  protected editingFinancialEntryId: number | null = null;
  protected activities: Activity[] = [];
  protected generalPendings: GeneralPending[] = [];
  protected financialEntries: FinancialEntry[] = [];
  protected newActivity: Omit<Activity, 'id'> = {
    title: '',
    startTime: '',
    endTime: '',
    assignee: 'Steelsoft',
    completed: false,
    location: '',
    description: '',
    date: this.selectedDate
  };
  protected newGeneralPending: Omit<GeneralPending, 'id'> = {
    title: '',
    assignee: 'Steelsoft',
    description: '',
    date: this.toIsoDate(new Date())
  };
  protected newFinancialEntry: Omit<FinancialEntry, 'id'> = {
    title: '',
    type: 'income',
    amount: 0,
    assignee: 'Steelsoft',
    description: '',
    date: this.toIsoDate(new Date())
  };

  ngOnInit(): void {
    this.loadRemoteData();
  }

  protected setViewMode(mode: ViewMode): void {
    this.viewMode = mode;
  }

  protected previousPeriod(): void {
    if (this.viewMode === 'month') {
      this.currentMonth = new Date(
        this.currentMonth.getFullYear(),
        this.currentMonth.getMonth() - 1,
        1
      );
      return;
    }

    const previousWeek = this.addDays(this.selectedDateAsDate, -7);
    this.selectDate(this.toIsoDate(previousWeek));
  }

  protected nextPeriod(): void {
    if (this.viewMode === 'month') {
      this.currentMonth = new Date(
        this.currentMonth.getFullYear(),
        this.currentMonth.getMonth() + 1,
        1
      );
      return;
    }

    const nextWeek = this.addDays(this.selectedDateAsDate, 7);
    this.selectDate(this.toIsoDate(nextWeek));
  }

  protected selectDate(isoDate: string): void {
    const normalizedDate = this.normalizeCalendarDate(new Date(`${isoDate}T00:00:00`));
    const normalizedIsoDate = this.toIsoDate(normalizedDate);
    this.selectedDate = normalizedIsoDate;
    this.newActivity.date = normalizedIsoDate;
    this.currentMonth = this.startOfMonth(normalizedDate);
  }

  protected openActivityPanel(isoDate: string): void {
    this.selectDate(isoDate);
    this.isActivityPanelOpen = true;
  }

  protected closeActivityPanel(): void {
    this.isActivityPanelOpen = false;
    this.resetActivityForm();
  }

  protected openPendingPanel(): void {
    this.isPendingPanelOpen = true;
  }

  protected closePendingPanel(): void {
    this.isPendingPanelOpen = false;
    this.resetGeneralPendingForm();
  }

  protected openFinancePanel(): void {
    this.isFinancePanelOpen = true;
    this.loadFinancialEntries();
  }

  protected closeFinancePanel(): void {
    this.isFinancePanelOpen = false;
    this.resetFinancialEntryForm();
  }

  protected addActivity(): void {
    const title = this.newActivity.title.trim();
    const startTime = this.newActivity.startTime;
    const endTime = this.newActivity.endTime;
    const assignee = this.newActivity.assignee;
    const location = this.newActivity.location.trim();
    const description = this.newActivity.description.trim();

    if (!title || !this.newActivity.date || !startTime || !endTime || !assignee) {
      return;
    }

    if (this.compareTimes(startTime, endTime) >= 0) {
      return;
    }

    const activity: Activity = {
      id: this.editingActivityId ?? Date.now(),
      title,
      startTime,
      endTime,
      assignee,
      completed: this.newActivity.completed,
      location,
      description,
      date: this.newActivity.date
    };

    const request =
      this.editingActivityId === null
        ? this.agendaApi.createActivity(this.toActivityPayload(activity))
        : this.agendaApi.updateActivity(
            this.editingActivityId,
            this.toActivityPayload({ ...activity, id: this.editingActivityId })
          );

    request.subscribe((savedActivity) => {
      this.activities = (
        this.editingActivityId === null
          ? [...this.activities, savedActivity]
          : this.activities.map((existingActivity) =>
              existingActivity.id === this.editingActivityId ? savedActivity : existingActivity
            )
      ).sort((left, right) => this.compareActivities(left, right));
      this.selectDate(savedActivity.date);
      this.resetActivityForm();
    });
  }

  protected removeActivity(activityId: number): void {
    this.agendaApi.deleteActivity(activityId).subscribe(() => {
      this.activities = this.activities.filter((activity) => activity.id !== activityId);

      if (this.editingActivityId === activityId) {
        this.resetActivityForm();
      }
    });
  }

  protected toggleActivityCompleted(activityId: number): void {
    const targetActivity = this.activities.find((activity) => activity.id === activityId);

    if (!targetActivity) {
      return;
    }

    const updatedActivity = { ...targetActivity, completed: !targetActivity.completed };

    this.agendaApi
      .updateActivity(activityId, this.toActivityPayload(updatedActivity))
      .subscribe((savedActivity) => {
        this.activities = this.activities
          .map((activity) => (activity.id === activityId ? savedActivity : activity))
          .sort((left, right) => this.compareActivities(left, right));
      });
  }

  protected editActivity(activity: Activity): void {
    this.editingActivityId = activity.id;
    this.isActivityPanelOpen = true;
    this.newActivity = {
      title: activity.title,
      startTime: activity.startTime,
      endTime: activity.endTime,
      assignee: activity.assignee,
      completed: activity.completed,
      location: activity.location,
      description: activity.description,
      date: activity.date
    };
    this.selectDate(activity.date);
  }

  protected cancelActivityEdit(): void {
    this.resetActivityForm();
  }

  protected get isEditingActivity(): boolean {
    return this.editingActivityId !== null;
  }

  protected get isEditingGeneralPending(): boolean {
    return this.editingGeneralPendingId !== null;
  }

  protected get isEditingFinancialEntry(): boolean {
    return this.editingFinancialEntryId !== null;
  }

  protected addGeneralPending(): void {
    const title = this.newGeneralPending.title.trim();
    const assignee = this.newGeneralPending.assignee;
    const description = this.newGeneralPending.description.trim();
    const date = this.newGeneralPending.date;

    if (!title || !assignee || !date) {
      return;
    }

    const pending: GeneralPending = {
      id: this.editingGeneralPendingId ?? Date.now(),
      title,
      assignee,
      description,
      date
    };

    const request =
      this.editingGeneralPendingId === null
        ? this.agendaApi.createGeneralPending({ title, assignee, description, date })
        : this.agendaApi.updateGeneralPending(this.editingGeneralPendingId, {
            title,
            assignee,
            description,
            date
          });

    request.subscribe((savedPending) => {
      this.generalPendings = (
        this.editingGeneralPendingId === null
          ? [...this.generalPendings, savedPending]
          : this.generalPendings.map((existingPending) =>
              existingPending.id === this.editingGeneralPendingId ? savedPending : existingPending
            )
      ).sort((left, right) => left.title.localeCompare(right.title));
      this.resetGeneralPendingForm();
    });
  }

  protected removeGeneralPending(pendingId: number): void {
    this.agendaApi.deleteGeneralPending(pendingId).subscribe(() => {
      this.generalPendings = this.generalPendings.filter((pending) => pending.id !== pendingId);

      if (this.editingGeneralPendingId === pendingId) {
        this.resetGeneralPendingForm();
      }
    });
  }

  protected editGeneralPending(pending: GeneralPending): void {
    this.isPendingPanelOpen = true;
    this.editingGeneralPendingId = pending.id;
    this.newGeneralPending = {
      title: pending.title,
      assignee: pending.assignee,
      description: pending.description,
      date: pending.date
    };
  }

  protected cancelGeneralPendingEdit(): void {
    this.resetGeneralPendingForm();
  }

  protected addFinancialEntry(): void {
    const title = this.newFinancialEntry.title.trim();
    const type = this.newFinancialEntry.type;
    const amount = Number(this.newFinancialEntry.amount);
    const assignee = this.newFinancialEntry.assignee;
    const description = this.newFinancialEntry.description.trim();
    const date = this.newFinancialEntry.date;

    if (!title || !type || !assignee || !date || !Number.isFinite(amount) || amount <= 0) {
      return;
    }

    const entry: FinancialEntry = {
      id: this.editingFinancialEntryId ?? Date.now(),
      title,
      type,
      amount,
      assignee,
      description,
      date
    };

    const request =
      this.editingFinancialEntryId === null
        ? this.agendaApi.createFinancialEntry({ title, type, amount, assignee, description, date })
        : this.agendaApi.updateFinancialEntry(this.editingFinancialEntryId, {
            title,
            type,
            amount,
            assignee,
            description,
            date
          });

    request.subscribe((savedEntry) => {
      this.financialEntries = (
        this.editingFinancialEntryId === null
          ? [...this.financialEntries, savedEntry]
          : this.financialEntries.map((existingEntry) =>
              existingEntry.id === this.editingFinancialEntryId ? savedEntry : existingEntry
            )
      ).sort((left, right) => this.compareFinancialEntries(left, right));
      this.resetFinancialEntryForm();
    });
  }

  protected editFinancialEntry(entry: FinancialEntry): void {
    this.isFinancePanelOpen = true;
    this.editingFinancialEntryId = entry.id;
    this.newFinancialEntry = {
      title: entry.title,
      type: entry.type,
      amount: entry.amount,
      assignee: entry.assignee,
      description: entry.description,
      date: entry.date
    };
  }

  protected cancelFinancialEntryEdit(): void {
    this.resetFinancialEntryForm();
  }

  protected removeFinancialEntry(entryId: number): void {
    this.agendaApi.deleteFinancialEntry(entryId).subscribe(() => {
      this.financialEntries = this.financialEntries.filter((entry) => entry.id !== entryId);

      if (this.editingFinancialEntryId === entryId) {
        this.resetFinancialEntryForm();
      }
    });
  }

  protected get currentMonthLabel(): string {
    return `${this.monthNames[this.currentMonth.getMonth()]} ${this.currentMonth.getFullYear()}`;
  }

  protected get currentPeriodLabel(): string {
    return this.viewMode === 'month' ? this.currentMonthLabel : this.currentWeekLabel;
  }

  protected get currentWeekLabel(): string {
    const weekStart = this.selectedWeekStart;
    const weekEnd = this.getWeekEnd(weekStart);
    return `${this.formatLongDate(weekStart)} - ${this.formatLongDate(weekEnd)}`;
  }

  protected get calendarDays(): CalendarDay[] {
    const firstDayOfMonth = this.startOfMonth(this.currentMonth);
    const startOffset = (firstDayOfMonth.getDay() + 6) % 7;
    const gridStart = new Date(firstDayOfMonth);
    gridStart.setDate(firstDayOfMonth.getDate() - startOffset);
    const lastDayOfMonth = new Date(
      this.currentMonth.getFullYear(),
      this.currentMonth.getMonth() + 1,
      0
    );
    const endOffset = (6 - ((lastDayOfMonth.getDay() + 6) % 7) + 6) % 6;
    const gridEnd = this.addDays(lastDayOfMonth, endOffset);
    const today = this.toIsoDate(new Date());
    const days: CalendarDay[] = [];

    for (let date = new Date(gridStart); date <= gridEnd; date = this.addDays(date, 1)) {
      if (date.getDay() === 0) {
        continue;
      }

      const isoDate = this.toIsoDate(date);
      days.push({
        date: new Date(date),
        isoDate,
        dayNumber: date.getDate(),
        inCurrentMonth: date.getMonth() === this.currentMonth.getMonth(),
        isToday: isoDate === today,
        activities: this.getActivitiesForDate(isoDate)
      });
    }

    return days;
  }

  protected get selectedDateActivities(): Activity[] {
    return this.getActivitiesForDate(this.selectedDate);
  }

  protected get selectedWeekStart(): Date {
    return this.getWeekStart(this.selectedDateAsDate);
  }

  protected get weeklyCalendarDays(): CalendarDay[] {
    const weekStart = this.selectedWeekStart;
    const today = this.toIsoDate(new Date());

    return Array.from({ length: 6 }, (_, index) => {
      const date = this.addDays(weekStart, index);
      const isoDate = this.toIsoDate(date);

      return {
        date,
        isoDate,
        dayNumber: date.getDate(),
        inCurrentMonth: date.getMonth() === this.currentMonth.getMonth(),
        isToday: isoDate === today,
        activities: this.getActivitiesForDate(isoDate)
      };
    });
  }

  protected get weeklyGroups(): WeekGroup[] {
    const monthDays = this.calendarDays.filter((day) => day.inCurrentMonth);
    const weeks = new Map<string, Activity[]>();

    for (const day of monthDays) {
      const weekStart = this.getWeekStart(day.date);
      const label = `${this.formatShortDate(weekStart)} - ${this.formatShortDate(
        this.getWeekEnd(weekStart)
      )}`;

      const existingActivities = weeks.get(label) ?? [];
      weeks.set(label, [...existingActivities, ...day.activities]);
    }

    return Array.from(weeks.entries()).map(([label, activities]) => ({
      label,
      activities: activities.sort((left, right) => this.compareActivities(left, right))
    }));
  }

  protected trackByIsoDate(_index: number, day: CalendarDay): string {
    return day.isoDate;
  }

  protected trackByActivity(_index: number, activity: Activity): number {
    return activity.id;
  }

  protected trackByPending(_index: number, pending: GeneralPending): number {
    return pending.id;
  }

  protected trackByFinancialEntry(_index: number, entry: FinancialEntry): number {
    return entry.id;
  }

  protected formatDayLabel(isoDate: string): string {
    if (!this.isValidIsoDate(isoDate)) {
      return 'Sin fecha';
    }

    const date = new Date(`${isoDate}T00:00:00`);
    return `${this.fullWeekDayNames[date.getDay()]} ${date.getDate()}`;
  }

  protected formatFullDateLabel(isoDate: string): string {
    if (!this.isValidIsoDate(isoDate)) {
      return 'Sin fecha';
    }

    const date = new Date(`${isoDate}T00:00:00`);
    return `${this.fullWeekDayNames[date.getDay()]} ${date.getDate()} ${this.monthNames[date.getMonth()]} ${date.getFullYear()}`;
  }

  protected formatTimeLabel(time: string): string {
    if (!time) {
      return 'Sin hora';
    }

    const [hoursText, minutesText] = time.split(':');
    const hours = Number(hoursText);
    const suffix = hours >= 12 ? 'PM' : 'AM';
    const normalizedHour = hours % 12 || 12;
    return `${normalizedHour}:${minutesText} ${suffix}`;
  }

  protected formatWeekDayHeader(day: CalendarDay): string {
    return `${this.weekDayNames[(day.date.getDay() + 6) % 7]} ${day.dayNumber}`;
  }

  protected getActivitiesForTimeSlot(isoDate: string, time: string): Activity[] {
    return this.getActivitiesForDate(isoDate).filter((activity) => activity.startTime === time);
  }

  protected getActivitiesForWeekHourSlot(isoDate: string, time: string): Activity[] {
    return this.getActivitiesForDate(isoDate).filter(
      (activity) => this.getHourSlot(activity.startTime) === time
    );
  }

  protected getActivitiesForWeekDay(isoDate: string): Activity[] {
    return this.getActivitiesForDate(isoDate);
  }

  protected getWeekActivityTop(activity: Activity): number {
    const startMinutes = this.parseTimeToMinutes(activity.startTime);
    const dayStartMinutes = this.weeklyHourStart * 60;
    return ((startMinutes - dayStartMinutes) / 60) * this.weeklyRowHeight;
  }

  protected getWeekActivityHeight(activity: Activity): number {
    const startMinutes = this.parseTimeToMinutes(activity.startTime);
    const endMinutes = this.parseTimeToMinutes(activity.endTime);
    const durationMinutes = Math.max(endMinutes - startMinutes, 30);
    return (durationMinutes / 60) * this.weeklyRowHeight;
  }

  protected formatActivityTimeRange(activity: Activity): string {
    return `${this.formatTimeLabel(activity.startTime)} - ${this.formatTimeLabel(activity.endTime)}`;
  }

  protected getAssigneeClassName(assignee: string): string {
    return `assignee-${assignee.toLowerCase()}`;
  }

  protected getFinancialTypeLabel(type: FinancialEntry['type']): string {
    return type === 'income' ? 'Ingreso' : 'Egreso';
  }

  protected formatCurrency(amount: number): string {
    return new Intl.NumberFormat('es-CO', {
      style: 'currency',
      currency: 'COP',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0
    }).format(amount);
  }

  private get selectedDateAsDate(): Date {
    return new Date(`${this.selectedDate}T00:00:00`);
  }

  private getActivitiesForDate(isoDate: string): Activity[] {
    return this.activities.filter((activity) => activity.date === isoDate);
  }

  private loadRemoteData(): void {
    forkJoin({
      activities: this.agendaApi.getActivities().pipe(catchError(() => of([] as Activity[]))),
      generalPendings: this.agendaApi
        .getGeneralPendings()
        .pipe(catchError(() => of([] as GeneralPending[]))),
      financialEntries: this.loadFinancialEntriesRequest()
    }).subscribe(({ activities, generalPendings, financialEntries }) => {
      this.activities = activities.sort((left, right) => this.compareActivities(left, right));
      this.generalPendings = generalPendings.sort((left, right) =>
        this.compareGeneralPendings(left, right)
      );
      this.financialEntries = financialEntries.sort((left, right) =>
        this.compareFinancialEntries(left, right)
      );
    });
  }

  private toActivityPayload(activity: Activity): Omit<Activity, 'id'> {
    return {
      title: activity.title,
      startTime: activity.startTime,
      endTime: activity.endTime,
      assignee: activity.assignee,
      completed: activity.completed,
      location: activity.location,
      description: activity.description,
      date: activity.date
    };
  }

  private resetActivityForm(): void {
    this.editingActivityId = null;
    this.newActivity = {
      title: '',
      startTime: '',
      endTime: '',
      assignee: 'Steelsoft',
      completed: false,
      location: '',
      description: '',
      date: this.selectedDate
    };
  }

  private resetGeneralPendingForm(): void {
    this.editingGeneralPendingId = null;
    this.newGeneralPending = {
      title: '',
      assignee: 'Steelsoft',
      description: '',
      date: this.toIsoDate(new Date())
    };
  }

  private resetFinancialEntryForm(): void {
    this.editingFinancialEntryId = null;
    this.newFinancialEntry = {
      title: '',
      type: 'income',
      amount: 0,
      assignee: 'Steelsoft',
      description: '',
      date: this.toIsoDate(new Date())
    };
  }

  private loadFinancialEntries(): void {
    this.loadFinancialEntriesRequest().subscribe((financialEntries) => {
      this.financialEntries = financialEntries.sort((left, right) =>
        this.compareFinancialEntries(left, right)
      );
    });
  }

  private loadFinancialEntriesRequest() {
    return this.agendaApi.getFinancialEntries().pipe(catchError(() => of([] as FinancialEntry[])));
  }

  private startOfMonth(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), 1);
  }

  private toIsoDate(date: Date): string {
    const year = date.getFullYear();
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  private normalizeCalendarDate(date: Date): Date {
    return date.getDay() === 0 ? this.addDays(date, -1) : date;
  }

  private isValidIsoDate(value: string): boolean {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
      return false;
    }

    const date = new Date(`${value}T00:00:00`);
    return !Number.isNaN(date.getTime());
  }

  private getWeekStart(date: Date): Date {
    const weekStart = new Date(date);
    const dayIndex = (date.getDay() + 6) % 7;
    weekStart.setDate(date.getDate() - dayIndex);
    return weekStart;
  }

  private getWeekEnd(date: Date): Date {
    return this.addDays(this.getWeekStart(date), 5);
  }

  private addDays(date: Date, days: number): Date {
    const result = new Date(date);
    result.setDate(result.getDate() + days);
    return result;
  }

  private formatShortDate(date: Date): string {
    return `${date.getDate()} ${this.monthNames[date.getMonth()].slice(0, 3)}`;
  }

  private formatLongDate(date: Date): string {
    return `${date.getDate()} ${this.monthNames[date.getMonth()]} ${date.getFullYear()}`;
  }

  private buildTimeOptions(): string[] {
    const times: string[] = [];

    for (let hour = 8; hour <= 18; hour += 1) {
      times.push(`${`${hour}`.padStart(2, '0')}:00`);

      if (hour < 18) {
        times.push(`${`${hour}`.padStart(2, '0')}:30`);
      }
    }

    return times;
  }

  private buildWeeklyTimeOptions(): string[] {
    const times: string[] = [];

    for (let hour = 8; hour <= 18; hour += 1) {
      times.push(`${`${hour}`.padStart(2, '0')}:00`);
    }

    return times;
  }

  private getHourSlot(time: string): string {
    const [hoursText] = time.split(':');
    return `${hoursText}:00`;
  }

  private parseTimeToMinutes(time: string): number {
    const [hoursText, minutesText] = time.split(':');
    return Number(hoursText) * 60 + Number(minutesText);
  }

  private getDefaultEndTime(startTime: string): string {
    const currentIndex = this.timeOptions.indexOf(startTime);

    if (currentIndex === -1 || currentIndex === this.timeOptions.length - 1) {
      return startTime;
    }

    return this.timeOptions[currentIndex + 1];
  }

  private compareTimes(left: string, right: string): number {
    return left.localeCompare(right);
  }

  private compareActivities(left: Activity, right: Activity): number {
    const leftKey = `${left.date}|${left.startTime}|${left.endTime}|${left.completed ? 1 : 0}|${left.title}`;
    const rightKey = `${right.date}|${right.startTime}|${right.endTime}|${right.completed ? 1 : 0}|${right.title}`;
    return leftKey.localeCompare(rightKey);
  }

  private compareGeneralPendings(left: GeneralPending, right: GeneralPending): number {
    const dateComparison = (right.date ?? '').localeCompare(left.date ?? '');
    if (dateComparison !== 0) {
      return dateComparison;
    }

    return `${left.title}|${left.assignee}`.localeCompare(`${right.title}|${right.assignee}`);
  }

  private compareFinancialEntries(left: FinancialEntry, right: FinancialEntry): number {
    const dateComparison = (right.date ?? '').localeCompare(left.date ?? '');
    if (dateComparison !== 0) {
      return dateComparison;
    }

    return `${left.type}|${left.title}|${left.amount}|${left.assignee}`.localeCompare(
      `${right.type}|${right.title}|${right.amount}|${right.assignee}`
    );
  }
}
